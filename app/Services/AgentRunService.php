<?php

namespace App\Services;

use App\Enums\AgentRunChangeStatus;
use App\Enums\AgentRunStatus;
use App\Enums\Entitlement;
use App\Events\AgentRunChanged;
use App\Http\Middleware\EnsureWorkspaceWritable;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentRole;
use App\Models\AgentRun;
use App\Models\AgentRunChange;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiProviderError;
use App\Services\Ai\RunPrompt;
use App\Services\Ai\RunTools;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Step B (Mode C): starting, working and stopping in-app agent runs. A person starts one in the web app (never a token,
 * never automatically) with one of their own active agents; the queued RunAgent job then loops provider ↔ tools within
 * the workspace's limits, re-checking before every step (B-R15). Runs only propose (B-D8, direct apply is Task 3).
 */
class AgentRunService
{
    public const MAX_BATCH = 25;

    /** Tool results kept in a run's steps, so the row stays small. */
    private const RESULT_CHARS = 2000;

    private const MODE_C_OFF = 'In-app agent runs are off for this project.';

    public function __construct(
        private AiSettingsService $ai,
        private Entitlements $entitlements,
        private CurrentWorkspace $current,
        private RunTools $tools,
        private NotificationService $notifications,
        private AiClient $client,
    ) {}

    public function start(User $starter, Agent $agent, Issue $issue, ?AgentRole $role, ?string $instruction): AgentRun
    {
        return $this->startMany($starter, $agent, collect([$issue]), $role, $instruction)->first();
    }

    /**
     * One run per issue (B-R10), all or none: the day limit counts every one. Refused when Mode C is off, the agent isn't
     * the starter's or can't act, an issue is out of the agent's sight, or the month's cap is used (B-D10).
     *
     * @param  Collection<int, Issue>  $issues
     * @return Collection<int, AgentRun>
     */
    public function startMany(User $starter, Agent $agent, Collection $issues, ?AgentRole $role, ?string $instruction): Collection
    {
        if ($starter->viaToken()) {
            throw new AuthorizationException('Agent runs are started by people in PmOps, not by tokens.');
        }
        if ($issues->count() > self::MAX_BATCH) {
            throw ValidationException::withMessages(['issues' => 'Start at most '.self::MAX_BATCH.' runs at once.']);
        }
        $instruction = filled($instruction) ? trim($instruction) : null;
        if (! $role && $instruction === null) {
            throw ValidationException::withMessages(['role' => 'Pick a role or type an instruction.']);
        }
        $workspace = $this->current->get();
        if ($stop = $this->readOnly($workspace)) {
            throw ValidationException::withMessages(['workspace' => $stop]);
        }
        if ($role && $role->workspace_id !== null && $role->workspace_id !== $workspace->id) {
            throw ValidationException::withMessages(['role' => 'That role was not found.'])->status(404);
        }
        if ($agent->owner_user_id !== $starter->id || $agent->workspace_id !== $workspace->id) {
            throw ValidationException::withMessages(['agent' => 'Choose one of your own agents.']);
        }
        if ($stop = $this->tools->unusable($agent)) {
            throw ValidationException::withMessages(['agent' => $stop]);
        }
        // Seen as the agent: the starter's projects, narrowed to the agent's (B-R1), in the current workspace.
        $asAgent = (new AgentRun(['user_id' => $starter->id]))->setRelation('agent', $agent);
        foreach ($issues as $issue) {
            $issue->loadMissing('project');
            // A run's proposals are changes to the issue: the starter must be able to make them (Viewers can't).
            Gate::forUser($starter)->authorize('update', $issue);
            if (! $this->ai->modeCEnabled($issue->project)) {
                throw ValidationException::withMessages(['issue' => self::MODE_C_OFF]);
            }
            if (! ActivityLogger::forRun($asAgent, fn () => Issue::visibleTo($starter)->whereKey($issue->id)->exists())) {
                throw ValidationException::withMessages(['issue' => "{$agent->label()} can't see {$issue->key}. Add its project to the agent in Settings → Agents."]);
            }
        }
        $runs = $this->entitlements->locked($workspace, function () use ($workspace, $starter, $agent, $issues, $role, $instruction) {
            // Inside the lock, so concurrent starts are serialized on the month's cap.
            if ($stop = $this->overCap($workspace)) {
                throw ValidationException::withMessages(['issue' => $stop]);
            }
            $today = AgentRun::where('workspace_id', $workspace->id)->where('user_id', $starter->id)->where('created_at', '>=', now()->utc()->startOfDay())->count();
            $this->entitlements->ensureCount($workspace, Entitlement::AgentRunsPerDay, $today, $issues->count());

            return $issues->map(fn (Issue $issue) => AgentRun::create([
                'workspace_id' => $workspace->id, 'issue_id' => $issue->id, 'issue_key' => $issue->key, 'user_id' => $starter->id, 'agent_id' => $agent->id,
                'role_id' => $role?->id, 'role_name' => $role->name ?? 'Instruction', 'instruction' => $instruction, 'status' => AgentRunStatus::Queued,
            ]))->values();
        });
        foreach ($runs as $run) {
            RunAgent::dispatch($run->id, (int) $workspace->setting('run_timeout_seconds'))->afterCommit();
            event(new AgentRunChanged($starter->id, $run->id, 'queued'));
        }

        return $runs;
    }

    /** The starter stops their run; a call in flight finishes first and its tokens count (B-R15). No notification. */
    public function cancel(AgentRun $run, User $user): void
    {
        if (! AgentRun::visibleTo($user)->whereKey($run->id)->exists()) {
            throw ValidationException::withMessages(['run' => "Run {$run->id} was not found."])->status(404);
        }
        if (AgentRun::whereKey($run->id)->whereIn('status', [AgentRunStatus::Queued, AgentRunStatus::Running])->update(['status' => AgentRunStatus::Cancelled, 'finished_at' => now()])) {
            event(new AgentRunChanged($run->user_id, $run->id, 'cancelled'));
        }
    }

    /** The job's body (B-R2): any error fails the run with a plain message, never a provider body. */
    public function run(int $runId): void
    {
        $run = AgentRun::with(['user', 'agent', 'role'])->find($runId);
        $now = now();
        // Atomic, so a run cancelled while queued never starts.
        if (! $run || ! AgentRun::whereKey($run->id)->where('status', AgentRunStatus::Queued)->update(['status' => AgentRunStatus::Running, 'started_at' => $now])) {
            return;
        }
        $run->forceFill(['status' => AgentRunStatus::Running, 'started_at' => $now])->syncOriginal();
        event(new AgentRunChanged($run->user_id, $run->id, 'running'));

        $previous = $this->current->id() ? $this->current->get() : null;
        $workspace = Workspace::findOrFail($run->workspace_id);
        $this->current->set($workspace);
        try {
            ActivityLogger::forRun($run, fn () => $this->loop($run, $workspace));
        } catch (AiProviderError $e) {
            $this->end($run, AgentRunStatus::Failed, $e->getMessage());
        } catch (ValidationException $e) {
            // The guard refusing the provider's address: its message names the rule, not the key.
            $this->end($run, AgentRunStatus::Failed, collect($e->errors())->flatten()->first());
        } catch (Throwable $e) {
            // Never the message, trace or previous: they can carry a provider body, issue data or headers.
            Log::error('Agent run crashed', ['run_id' => $runId, 'exception' => $e::class, 'at' => basename($e->getFile()).':'.$e->getLine()]);
            $this->end($run, AgentRunStatus::Failed, 'The run stopped because of an error. Try again.');
        } finally {
            $previous ? $this->current->set($previous) : $this->current->forget();
        }
    }

    /** RunAgent::failed(): the worker gave up on the job (its timeout), so the run doesn't stay running. */
    public function crashed(int $runId): void
    {
        if ($run = AgentRun::find($runId)) {
            $this->end($run, AgentRunStatus::Failed, 'The run stopped unexpectedly. Try again.');
        }
    }

    /** B-D5: runs older than 30 days keep who, when, the issue and tokens; their steps, instruction and unapplied proposals go. Returns how many runs. */
    public function prune(): int
    {
        $old = fn () => AgentRun::where('created_at', '<', now()->subDays(30));
        // `after` can't be null: an empty list.
        AgentRunChange::whereIn('run_id', $old()->select('id'))->where('status', '!=', AgentRunChangeStatus::Applied)->update(['before' => null, 'after' => '[]']);

        return $old()->where(fn ($q) => $q->whereNotNull('steps')->orWhereNotNull('instruction'))->update(['steps' => null, 'instruction' => null]);
    }

    private function loop(AgentRun $run, Workspace $workspace): void
    {
        $issue = $run->user ? Issue::visibleTo($run->user)->with('project')->find($run->issue_id) : null;
        if (! $issue) {
            $this->end($run, AgentRunStatus::Failed, "{$run->issue_key} was deleted, or the agent can no longer see it.");

            return;
        }
        $run->setRelation('issue', $issue);
        $deadline = $run->started_at->copy()->addSeconds((int) $workspace->setting('run_timeout_seconds'));
        $tools = RunTools::for($run->role);
        $system = RunPrompt::system($run);
        $messages = [['role' => 'user', 'content' => RunPrompt::context($run)]];
        $steps = [];

        for ($step = 1; ; $step++) {
            if ($this->cancelled($run)) {
                return;
            }
            if ($stop = $this->stopReason($run, $issue, $workspace, $step, $deadline)) {
                $this->end($run, AgentRunStatus::Failed, $stop);

                return;
            }
            // B-R3: one buffered call, given at most 60 s or what is left.
            $reply = $this->client->chat($workspace, $system, $messages, $tools, (int) max(1, min(60, now()->diffInSeconds($deadline, false))));
            // An estimate is counted (B-R9) but marked, so nothing shows it as the provider's own count.
            $entry = ['tokens_in' => $reply->tokensIn, 'tokens_out' => $reply->tokensOut, ...($reply->usageEstimated ? ['estimated' => true] : [])];
            $run->tokens_in += (int) $reply->tokensIn;
            $run->tokens_out += (int) $reply->tokensOut;
            if ($this->cancelled($run)) {
                $this->save($run, [...$steps, $entry]);

                return;
            }
            // A reply that crosses the budget is not acted on: its calls aren't run and its text isn't proposed.
            if ($stop = $this->overBudget($run, (int) $workspace->setting('run_token_budget'))) {
                $this->save($run, [...$steps, $entry]);
                $this->end($run, AgentRunStatus::Failed, $stop);

                return;
            }
            if (! $reply->toolCalls) {
                $this->save($run, [...$steps, $entry]);
                $this->end($run, AgentRunStatus::Finished, null, $reply->text);

                return;
            }

            $messages[] = ['role' => 'assistant', 'content' => $reply->text, 'tool_calls' => $reply->toolCalls];
            foreach ($reply->toolCalls as $call) {
                $result = $this->tools->call($run, (string) $call['name'], (array) $call['args']);
                $failed = isset($result['error']);
                // shortcut: results are cut to RESULT_CHARS in the stored log (the model got them whole), keep more if the run panel needs it.
                $entry['calls'][] = ['tool' => $call['name'], 'args' => $call['args'], ...($failed ? ['error' => $result['error']] : ['result' => Str::limit(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), self::RESULT_CHARS)])];
                // Results (errors too: they can echo model or issue text) are issue data, so they go back marked like the first message.
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'name' => $call['name'], 'content' => RunPrompt::untrusted($result)];
            }
            $steps[] = $entry;
            $this->save($run, $steps);
            event(new AgentRunChanged($run->user_id, $run->id, 'step'));
        }
    }

    /** B-R15, before each step, in order: agent usable, workspace writable, Mode C on, steps, budget, time, the month's cap. Null: go on. */
    private function stopReason(AgentRun $run, Issue $issue, Workspace $workspace, int $step, Carbon $deadline): ?string
    {
        // Fresh each step: a suspension or a settings change made during the run counts.
        $workspace->refresh();
        $maxSteps = (int) $workspace->setting('run_max_steps');
        $budget = (int) $workspace->setting('run_token_budget');
        $seconds = (int) $workspace->setting('run_timeout_seconds');

        return $this->tools->unusable($run->agent)
            ?? $this->readOnly($workspace)
            ?? (! $this->ai->modeCEnabled($issue->project) ? self::MODE_C_OFF : null)
            ?? ($step > $maxSteps ? "Stopped: reached the {$maxSteps}-step limit" : null)
            ?? $this->overBudget($run, $budget)
            ?? (now()->gte($deadline) ? 'Stopped: reached the '.($seconds % 60 ? "{$seconds}-second" : ($seconds / 60).'-minute').' time limit' : null)
            ?? $this->overCap($workspace);
    }

    private function overBudget(AgentRun $run, int $budget): ?string
    {
        return $run->tokens_in + $run->tokens_out >= $budget ? 'Stopped: reached the '.number_format($budget).'-token budget' : null;
    }

    /** B-D10: the month's usage reached the workspace's cap (none set: no cap). */
    private function overCap(Workspace $workspace): ?string
    {
        ['cap' => $cap, 'used' => $used] = $this->ai->usage($workspace);

        return $cap !== null && $used >= $cap
            ? 'Your workspace used its '.number_format($cap).' AI tokens for '.now()->utc()->format('F').'. An Owner or Admin can raise the cap in Settings → AI.'
            : null;
    }

    /** Why nothing may change in $workspace (suspended or archived), or null. */
    private function readOnly(Workspace $workspace): ?string
    {
        return $workspace->isWritable() ? null : EnsureWorkspaceWritable::MESSAGES[$workspace->state()];
    }

    private function cancelled(AgentRun $run): bool
    {
        return AgentRun::whereKey($run->id)->value('status') === AgentRunStatus::Cancelled;
    }

    /** Steps and tokens only: the status column stays as cancel() may have set it. */
    private function save(AgentRun $run, array $steps): void
    {
        $run->steps = $steps;
        $run->save();
    }

    /** Ends a run still queued or running (a cancelled one stays cancelled); the reply becomes a proposed comment (B-D8); the starter hears (B-R12). */
    private function end(AgentRun $run, AgentRunStatus $status, ?string $error, ?string $reply = null): void
    {
        DB::transaction(function () use ($run, $status, $error, $reply) {
            $ended = AgentRun::whereKey($run->id)->whereIn('status', [AgentRunStatus::Queued, AgentRunStatus::Running])
                ->update(['status' => $status, 'error' => $error === null ? null : Str::limit($error, 250), 'finished_at' => now()]);
            if (! $ended) {
                return;
            }
            // Loaded here: crashed() and the issue-gone path come with nothing loaded.
            $run->loadMissing(['issue.project', 'user', 'agent']);
            $issue = $run->issue;
            if (filled($reply)) {
                AgentRunChange::create(['run_id' => $run->id, 'kind' => 'comment', 'after' => ['body' => $reply], 'issue_version' => $issue?->version, 'status' => AgentRunChangeStatus::Proposed]);
            }
            if ($issue && $run->user) {
                ActivityLogger::forRun($run, fn () => $this->notifications->notify($run->user, $issue, "agent_run.{$status->value}",
                    ['action' => $status->value, 'from' => null, 'to' => $error], null, $run->agent, 'agent_run', batch: false));
            }
            event(new AgentRunChanged($run->user_id, $run->id, $status->value));
        });
    }
}
