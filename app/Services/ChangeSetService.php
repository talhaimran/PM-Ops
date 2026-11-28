<?php

namespace App\Services;

use App\Enums\AgentRunChangeStatus;
use App\Enums\IssueType;
use App\Events\AgentRunChanged;
use App\Exceptions\EditConflict;
use App\Models\AgentRun;
use App\Models\AgentRunChange;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Step B (B-R7, B-R8, B-R11): applying or discarding what an agent run proposed. Every write goes through IssueService,
 * LinkService or CommentService as $by (the starter); from the run panel the rows name the run and its agent
 * (ActivityLogger::forRunApply), and inside the job (direct apply, B-R1) $by already acts as the agent.
 * Each change is applied in its own transaction holding its row, so a double click can't apply it twice.
 */
class ChangeSetService
{
    public function __construct(private IssueService $issues, private LinkService $links, private CommentService $comments, private ActivityLogger $activity) {}

    /**
     * B-D6: the run (and change) if $user started it in the current workspace; anyone else is told it doesn't exist.
     *
     * @return array{0: AgentRun, 1: ?AgentRunChange}
     */
    public function resolve(User $user, int $runId, ?int $changeId = null): array
    {
        $run = AgentRun::visibleTo($user)->find($runId)
            ?? throw ValidationException::withMessages(['run' => "Run {$runId} was not found."])->status(404);
        $change = $changeId === null ? null : ($run->changes()->find($changeId)
            ?? throw ValidationException::withMessages(['change' => "Change {$changeId} was not found. Open the run to see its changes."])->status(404));

        return [$run, $change];
    }

    /**
     * Applies $only, or every proposed change in order. A field someone changed since the proposal, a link whose other
     * issue is gone or hidden, or anything a service refuses is marked `conflict` with the reason; the rest go on.
     *
     * @return array{applied: int, conflicts: list<array{id: int, message: string}>}
     */
    public function apply(AgentRun $run, User $by, ?AgentRunChange $only = null): array
    {
        $issue = $this->issue($run, $by, $only);
        // B-R7: they must be allowed to make the change themselves (a Viewer can't).
        Gate::forUser($by)->authorize('update', $issue);
        $changes = $only ? collect([$only]) : $run->changes()->where('status', AgentRunChangeStatus::Proposed)->orderBy('id')->get();
        $applied = 0;
        $conflicts = [];
        ActivityLogger::forRunApply($run, function () use ($changes, $issue, $by, $only, &$applied, &$conflicts) {
            foreach ($changes as $change) {
                $work = fn () => $this->applyOne($change->id, $issue, $by, (bool) $only);
                // Links take the workspace's link lock before any transaction (2-R4).
                $result = $change->kind === 'link' ? $this->links->withLinkLock($issue, $work) : $work();
                if ($result === true) {
                    $applied++;
                } elseif (is_string($result)) {
                    $conflicts[] = ['id' => $change->id, 'message' => $result];
                }
            }
        });
        event(new AgentRunChanged($run->user_id, $run->id, 'changes'));

        return ['applied' => $applied, 'conflicts' => $conflicts];
    }

    /** Discards $only, or every change not applied yet (proposed or conflict). Returns how many. */
    public function discard(AgentRun $run, User $by, ?AgentRunChange $only = null): int
    {
        $this->issue($run, $by, $only);
        // One conditional update: a change applied meanwhile is left alone.
        $count = $run->changes()->when($only, fn ($q) => $q->whereKey($only->id))
            ->whereIn('status', [AgentRunChangeStatus::Proposed, AgentRunChangeStatus::Conflict])->update(['status' => AgentRunChangeStatus::Discarded]);
        if ($only && ! $count) {
            throw self::settled($only->fresh());
        }
        event(new AgentRunChanged($run->user_id, $run->id, 'changes'));

        return $count;
    }

    /** True when applied, false when skipped (apply all: settled meanwhile), else the conflict's message. */
    private function applyOne(int $id, Issue $issue, User $by, bool $single): bool|string
    {
        return DB::transaction(function () use ($id, $issue, $by, $single) {
            $change = AgentRunChange::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($change->status !== AgentRunChangeStatus::Proposed) {
                return $single ? throw self::settled($change) : false;
            }
            try {
                $message = $this->write($change, $issue, $by);
            } catch (EditConflict $e) {
                $message = ActivityLogger::describe($e->payload['changes'], $issue->key).' since this was proposed.';
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->first();
            } catch (AuthorizationException $e) {
                // A policy refusal or a plan limit: nothing was written.
                $message = $e->getMessage();
            }
            $change->update(['status' => $message === null ? AgentRunChangeStatus::Applied : AgentRunChangeStatus::Conflict, 'message' => $message === null ? null : Str::limit($message, 250)]);

            return $message ?? true;
        });
    }

    /** Makes one change through its service; returns why it can't be made, or null once made. */
    private function write(AgentRunChange $change, Issue $issue, User $by): ?string
    {
        $after = $change->after;
        switch ($change->kind) {
            case 'field':
                // B-R8, per field: a newer edit of another field doesn't stop this one. The version read first, so an
                // edit after the check still fails IssueService's own version check (EditConflict).
                $version = Issue::whereKey($issue->id)->value('version');
                $touched = collect($this->activity->changesSince($issue, (int) $change->issue_version, $by, $truncated))->firstWhere('field', $change->target);
                if ($truncated && ! $touched) {
                    // Older rows were left out, so an edit of this field may be among them: never overwrite it unseen.
                    return "{$issue->key} changed too much since this was proposed to check this field. Discard it, or start a new run to propose it again.";
                }
                if ($touched) {
                    return ActivityLogger::describe([$touched], $issue->key).' since this was proposed.';
                }
                $this->issues->update($issue, $by, [$change->target => $after['value'], 'version' => $version]);
                break;
            case 'child_issue':
                $this->issues->create($issue->project, $by, [...$after, ($issue->type === IssueType::Epic ? 'epic' : 'parent') => $issue->key]);
                break;
            case 'link':
                $this->links->link($issue, $after['type'], $this->issues->resolve($by, $after['issue']), $by);
                break;
            case 'comment':
                $this->comments->add($issue, $by, $after['body']);
                break;
        }

        return null;
    }

    /** The run's issue as $by (its starter) sees it now: deleted or out of their sight, nothing is applied or discarded. */
    private function issue(AgentRun $run, User $by, ?AgentRunChange $only): Issue
    {
        if ($run->user_id !== $by->id) {
            throw ValidationException::withMessages(['run' => "Run {$run->id} was not found."])->status(404);
        }
        if ($only && $only->run_id !== $run->id) {
            throw ValidationException::withMessages(['change' => "Change {$only->id} was not found. Open the run to see its changes."])->status(404);
        }

        return Issue::visibleTo($by)->with('project')->find($run->issue_id)
            ?? throw ValidationException::withMessages(['issue' => "{$run->issue_key} was deleted, or you can no longer see it."])->status(404);
    }

    private static function settled(AgentRunChange $change): ValidationException
    {
        return ValidationException::withMessages(['change' => $change->status === AgentRunChangeStatus::Conflict
            ? 'This change is in conflict. Discard it, or start a new run to propose it again.'
            : "This change was already {$change->status->value}."])->status(409);
    }
}
