<?php

use App\Enums\AgentLevel;
use App\Enums\AgentRunChangeStatus;
use App\Enums\AgentRunStatus;
use App\Enums\Entitlement;
use App\Events\AgentRunChanged;
use App\Exceptions\PlanLimitReached;
use App\Jobs\RunAgent;
use App\Models\Activity;
use App\Models\AgentRole;
use App\Models\AgentRun;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\InboxItem;
use App\Models\ProjectAgentSettings;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AgentRunService;
use App\Services\AgentService;
use App\Services\Ai\AiProviderError;
use App\Services\Ai\AiReply;
use App\Services\Ai\RunTools;
use App\Services\AuditLogger;
use App\Services\Entitlements;
use App\Services\IssueService;
use App\Services\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

// Step B (B-R1, B-R2, B-R4, B-R10, B-R12..B-R17, B-D5..B-D10): starting, running and stopping in-app agent runs.

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create(['name' => 'Talha Imran']);
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->web = makeProject($this->owner, 'WEB');
    $this->issue = makeIssue($this->web, $this->owner, ['title' => 'Login fails', 'description' => 'Clicking Sign in does nothing.', 'priority' => 'medium']);
    [$this->agent] = makeAgent($this->owner, AgentLevel::Write, name: 'Helper');
    aiReady($this->ws);
    $this->runs = app(AgentRunService::class);
    $this->role = fn (string $name) => AgentRole::whereNull('workspace_id')->where('name', $name)->sole();
});

function startTriage($t): AgentRun
{
    return $t->runs->start($t->owner, $t->agent, $t->issue, ($t->role)('Triage'), null);
}

function refusal(Closure $start): string
{
    try {
        $start();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->implode(' ');
    }
    throw new RuntimeException('Not refused');
}

test('start queues a run for the starter with the role\'s name', function () {
    Event::fake([AgentRunChanged::class]);
    $run = startTriage($this);

    expect($run->only(['user_id', 'agent_id', 'issue_id', 'issue_key', 'role_name', 'workspace_id']))->toEqual([
        'user_id' => $this->owner->id, 'agent_id' => $this->agent->id, 'issue_id' => $this->issue->id, 'issue_key' => 'WEB-1', 'role_name' => 'Triage', 'workspace_id' => $this->ws->id,
    ])->and($run->status)->toBe(AgentRunStatus::Queued);
    Queue::assertPushed(RunAgent::class, fn (RunAgent $job) => $job->runId === $run->id && $job->tries === 1 && $job->timeout === 210);
    Event::assertDispatched(AgentRunChanged::class, fn ($e) => $e->userId === $this->owner->id && $e->runId === $run->id && $e->kind === 'queued');
});

test('start refuses when Mode C is off for the workspace, the project, or there is no provider', function () {
    $off = 'In-app agent runs are off for this project.';
    ProjectAgentSettings::updateOrCreate(['project_id' => $this->web->id], ['mode_c_enabled' => false]);
    expect(refusal(fn () => startTriage($this)))->toBe($off);

    ProjectAgentSettings::where('project_id', $this->web->id)->update(['mode_c_enabled' => true]);
    $this->ws->forceFill(['settings' => [...($this->ws->settings ?? []), 'mode_c_enabled' => false]])->save();
    expect(refusal(fn () => startTriage($this)))->toBe($off);

    $this->ws->forceFill(['settings' => [...$this->ws->settings, 'mode_c_enabled' => true]])->save();
    AiProvider::where('workspace_id', $this->ws->id)->delete();
    expect(refusal(fn () => startTriage($this)))->toBe($off)
        ->and(AgentRun::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('start refuses a paused, revoked or someone else\'s agent', function () {
    $other = User::factory()->create();
    joinWorkspace($other);
    [$theirs] = makeAgent($other, AgentLevel::Write);
    expect(refusal(fn () => $this->runs->start($this->owner, $theirs, $this->issue, ($this->role)('Triage'), null)))->toBe('Choose one of your own agents.');

    app(AgentService::class)->pause($this->agent, $this->owner);
    expect(refusal(fn () => startTriage($this)))->toContain('Helper is paused.');

    app(AgentService::class)->revoke($this->agent, $this->owner);
    expect(refusal(fn () => startTriage($this)))->toContain('Helper was revoked.')
        ->and(AgentRun::count())->toBe(0);
});

test('start refuses an issue outside the agent\'s projects', function () {
    $ops = makeProject($this->owner, 'OPS');
    $opsIssue = makeIssue($ops, $this->owner);
    [$scoped] = makeAgent($this->owner, AgentLevel::Write, ['WEB']);

    expect(refusal(fn () => $this->runs->start($this->owner, $scoped, $opsIssue, ($this->role)('Triage'), null)))
        ->toBe("{$scoped->name} can't see OPS-1. Add its project to the agent in Settings → Agents.");
});

test('start refuses past the person\'s runs per day, and startMany refuses the whole batch', function () {
    app(Entitlements::class)->set($this->ws, Entitlement::AgentRunsPerDay, 2, 'override');
    $two = makeIssue($this->web, $this->owner);
    $three = makeIssue($this->web, $this->owner);

    expect(fn () => $this->runs->startMany($this->owner, $this->agent, collect([$this->issue, $two, $three]), ($this->role)('Triage'), null))
        ->toThrow(PlanLimitReached::class);
    expect(AgentRun::count())->toBe(0);

    $this->runs->startMany($this->owner, $this->agent, collect([$this->issue, $two]), ($this->role)('Triage'), null);
    expect(fn () => startTriage($this))->toThrow(PlanLimitReached::class);

    // Yesterday's runs don't count.
    $this->travel(1)->days();
    expect(startTriage($this)->status)->toBe(AgentRunStatus::Queued);
});

test('startMany takes at most 25 issues', function () {
    expect(refusal(fn () => $this->runs->startMany($this->owner, $this->agent, collect(array_fill(0, 26, $this->issue)), ($this->role)('Triage'), null)))
        ->toBe('Start at most 25 runs at once.');
});

test('start refuses when the workspace used its monthly token cap', function () {
    $this->travelTo(now()->setDate(2026, 11, 15));
    aiReady($this->ws, ['monthly_token_cap' => 2000000]);
    AgentRun::create(['workspace_id' => $this->ws->id, 'issue_key' => 'WEB-1', 'user_id' => $this->owner->id, 'role_name' => 'Triage',
        'status' => AgentRunStatus::Finished, 'tokens_in' => 1500000, 'tokens_out' => 500000]);

    expect(refusal(fn () => startTriage($this)))
        ->toBe('Your workspace used its 2,000,000 AI tokens for November. An Owner or Admin can raise the cap in Settings → AI.');
});

test('start refuses a request made through a token: runs are started by people in the web app', function () {
    $user = apiAs($this->owner);

    expect(fn () => $this->runs->start($user, $this->agent, $this->issue, ($this->role)('Triage'), null))->toThrow(AuthorizationException::class);
});

test('start needs a role or a typed instruction; an instruction alone gets every tool', function () {
    expect(refusal(fn () => $this->runs->start($this->owner, $this->agent, $this->issue, null, ' ')))->toBe('Pick a role or type an instruction.');

    $ai = scriptAi([]);
    $run = runAgentNow($this->runs->start($this->owner, $this->agent, $this->issue, null, 'Tighten the wording'));

    expect($run->role_name)->toBe('Instruction')
        ->and(collect($ai->calls[0]['tools'])->pluck('name')->all())->toBe(['read_issue', 'search_issues', 'propose_fields', 'propose_child_issue', 'propose_link'])
        ->and($ai->calls[0]['messages'][0]['content'])->toContain('Tighten the wording');
});

test('a Triage run reads, proposes, and finishes with its reply as a proposed comment; nothing on the issue changes', function () {
    Event::fake([AgentRunChanged::class]);
    $ai = scriptAi([
        toolReply('read_issue', ['key' => 'WEB-1'], 300, 40),
        toolReply('propose_fields', ['priority' => 'high'], 500, 30),
        new AiReply('Looks like a P1', [], 600, 10),
    ]);
    $before = $this->issue->fresh()->only(['priority', 'version', 'title']);

    $run = runAgentNow(startTriage($this));

    expect($run->status)->toBe(AgentRunStatus::Finished)
        ->and($run->error)->toBeNull()
        ->and($run->started_at)->not->toBeNull()->and($run->finished_at)->not->toBeNull()
        ->and([$run->tokens_in, $run->tokens_out])->toBe([1400, 80])
        ->and(collect($run->steps)->flatMap(fn ($s) => $s['calls'] ?? [])->pluck('tool')->all())->toBe(['read_issue', 'propose_fields'])
        ->and(collect($run->steps)->map(fn ($s) => [$s['tokens_in'], $s['tokens_out']])->all())->toBe([[300, 40], [500, 30], [600, 10]])
        ->and($run->changes()->orderBy('id')->get()->map(fn ($c) => [$c->kind, $c->target, $c->before, $c->after, $c->status, $c->issue_version])->all())->toEqual([
            ['field', 'priority', ['value' => 'medium'], ['value' => 'high'], AgentRunChangeStatus::Proposed, $before['version']],
            ['comment', null, null, ['body' => 'Looks like a P1'], AgentRunChangeStatus::Proposed, $before['version']],
        ])
        ->and($this->issue->fresh()->only(['priority', 'version', 'title']))->toEqual($before)
        // The system prompt names the role; the model got Triage's tools only.
        ->and($ai->calls[0]['system'])->toContain('Triage')
        ->and(collect($ai->calls[0]['tools'])->pluck('name')->all())->toBe(['read_issue', 'search_issues', 'propose_fields', 'propose_link']);

    $item = InboxItem::where('user_id', $this->owner->id)->sole();
    expect($item->only(['event', 'actor_user_id', 'agent_id', 'agent_run_id', 'issue_id', 'via']))->toEqual([
        'event' => 'agent_run.finished', 'actor_user_id' => null, 'agent_id' => $this->agent->id, 'agent_run_id' => $run->id, 'issue_id' => $this->issue->id, 'via' => 'agent_run',
    ])->and(NotificationService::describe($item->load(['issue.project', 'actor', 'agent.owner'])))->toBe("Talha's agent finished its run on WEB-1");

    Event::assertDispatched(AgentRunChanged::class, fn ($e) => $e->runId === $run->id && $e->kind === 'finished' && $e->userId === $this->owner->id);
    $event = new AgentRunChanged($this->owner->id, $run->id, 'finished');
    expect($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->broadcastOn()->name)->toBe("private-user.{$this->owner->id}")
        ->and($event->broadcastWith())->toBe(['run_id' => $run->id, 'kind' => 'finished']);
});

test('a second proposal for the same field replaces the first', function () {
    scriptAi([toolReply('propose_fields', ['priority' => 'high', 'labels' => ['bug']]), toolReply('propose_fields', ['priority' => 'highest'])]);

    $run = runAgentNow(startTriage($this));

    expect($run->changes()->where('kind', 'field')->orderBy('target')->get()->map(fn ($c) => [$c->target, $c->after])->all())
        ->toEqual([['labels', ['value' => ['bug']]], ['priority', ['value' => 'highest']]]);
});

test('the 16th step stops the run as failed, keeping its proposals', function () {
    $ai = scriptAi([toolReply('propose_fields', ['priority' => 'high']), ...array_fill(0, 15, toolReply('read_issue', ['key' => 'WEB-1']))]);

    $run = runAgentNow(startTriage($this));

    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error)->toBe('Stopped: reached the 15-step limit')
        ->and($ai->calls)->toHaveCount(15)
        ->and($run->changes()->where('kind', 'field')->count())->toBe(1);
    expect(InboxItem::where('user_id', $this->owner->id)->sole()->event)->toBe('agent_run.failed');
});

test('the token budget and the time limit stop a run, using the configured numbers', function () {
    $ai = scriptAi([toolReply('propose_fields', ['priority' => 'high'], 30000, 100), toolReply('read_issue', ['key' => 'WEB-1'], 30000, 100)]);
    $run = runAgentNow(startTriage($this));
    expect([$run->status, $run->error])->toBe([AgentRunStatus::Failed, 'Stopped: reached the 50,000-token budget'])
        ->and($ai->calls)->toHaveCount(2)
        ->and($run->changes()->count())->toBe(1);

    $this->ws->forceFill(['settings' => [...($this->ws->settings ?? []), 'run_timeout_seconds' => 120]])->save();
    $ai = scriptAi([fn () => tap(toolReply('read_issue', ['key' => 'WEB-1']), fn () => $this->travel(121)->seconds())]);
    $run = runAgentNow(startTriage($this));
    expect([$run->status, $run->error])->toBe([AgentRunStatus::Failed, 'Stopped: reached the 2-minute time limit'])
        ->and($ai->calls)->toHaveCount(1)
        // B-R3: each call gets at most 60 s, or what is left.
        ->and($ai->calls[0]['timeout'])->toBe(60);
});

test('a run that crosses the monthly cap stops after that step', function () {
    aiReady($this->ws, ['monthly_token_cap' => 1000]);
    $ai = scriptAi([toolReply('read_issue', ['key' => 'WEB-1'], 900, 200), toolReply('read_issue', ['key' => 'WEB-1'])]);

    $run = runAgentNow(startTriage($this));

    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error)->toStartWith('Your workspace used its 1,000 AI tokens for ')
        ->and($ai->calls)->toHaveCount(1);
});

test('cancelling between steps lets the call in flight finish, then stops: no notification, tokens and proposals kept', function () {
    Event::fake([AgentRunChanged::class]);
    $run = startTriage($this);
    $ai = scriptAi([
        toolReply('propose_fields', ['priority' => 'high'], 100, 10),
        function () use ($run) {
            $this->runs->cancel($run, $this->owner);

            return toolReply('propose_fields', ['type' => 'bug'], 200, 20);
        },
        toolReply('read_issue', ['key' => 'WEB-1']),
    ]);

    $run = runAgentNow($run);

    expect($run->status)->toBe(AgentRunStatus::Cancelled)
        ->and($run->finished_at)->not->toBeNull()
        ->and($ai->calls)->toHaveCount(2)
        ->and([$run->tokens_in, $run->tokens_out])->toBe([300, 30])
        ->and($run->changes()->pluck('target')->all())->toBe(['priority'])
        ->and(InboxItem::count())->toBe(0);
    Event::assertDispatched(AgentRunChanged::class, fn ($e) => $e->kind === 'cancelled');
});

test('cancel is the starter\'s only, and a cancelled queued run never calls the provider', function () {
    $run = startTriage($this);
    $other = User::factory()->create();
    joinWorkspace($other);

    expect(refusal(fn () => $this->runs->cancel($run, $other)))->toBe("Run {$run->id} was not found.");

    $this->runs->cancel($run, $this->owner);
    $ai = scriptAi([toolReply('read_issue', ['key' => 'WEB-1'])]);
    expect(runAgentNow($run)->status)->toBe(AgentRunStatus::Cancelled)
        ->and($ai->calls)->toBe([]);
});

test('pausing the agent mid-run fails it at its next step with the paused message', function () {
    $ai = scriptAi([
        toolReply('propose_fields', ['priority' => 'high']),
        function () {
            app(AgentService::class)->pause($this->agent, $this->owner);

            return toolReply('propose_fields', ['type' => 'bug']);
        },
    ]);

    $run = runAgentNow(startTriage($this));

    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error)->toBe('Helper is paused. Its owner or a workspace Admin can resume it in Settings → Agents.')
        ->and($ai->calls)->toHaveCount(2)
        // The call made after the pause was refused too.
        ->and(collect($run->steps[1]['calls'])->pluck('error')->filter()->first())->toContain('paused')
        ->and($run->changes()->pluck('target')->all())->toBe(['priority']);
});

test('turning Mode C off mid-run stops it', function () {
    $ai = scriptAi([
        function () {
            ProjectAgentSettings::updateOrCreate(['project_id' => $this->web->id], ['mode_c_enabled' => false]);

            return toolReply('read_issue', ['key' => 'WEB-1']);
        },
        toolReply('read_issue', ['key' => 'WEB-1']),
    ]);

    $run = runAgentNow(startTriage($this));

    expect([$run->status, $run->error])->toBe([AgentRunStatus::Failed, 'In-app agent runs are off for this project.'])
        ->and($ai->calls)->toHaveCount(1);
});

test('a provider error fails the run with its own plain message', function () {
    scriptAi([fn () => throw new AiProviderError("The AI provider didn't answer (HTTP 500). Try again later.")]);
    $run = runAgentNow(startTriage($this));
    expect([$run->status, $run->error])->toBe([AgentRunStatus::Failed, "The AI provider didn't answer (HTTP 500). Try again later."]);

    scriptAi([fn () => throw new RuntimeException('SQLSTATE secret detail sk-RUN-SECRET')]);
    $run = runAgentNow(startTriage($this));
    expect([$run->status, $run->error])->toBe([AgentRunStatus::Failed, 'The run stopped because of an error. Try again.']);
});

test('a reply without usage marks its step estimated', function () {
    scriptAi([new AiReply('Fine as is', [], 40, 12, true)]);

    $run = runAgentNow(startTriage($this));

    expect($run->steps[0])->toMatchArray(['tokens_in' => 40, 'tokens_out' => 12, 'estimated' => true])
        ->and([$run->tokens_in, $run->tokens_out])->toBe([40, 12]);
});

test('B-R1: inside a run, the starter acts as the run\'s agent and every row names the run', function () {
    $run = startTriage($this);
    $other = User::factory()->create();

    ActivityLogger::forRun($run, function () use ($run, $other) {
        expect(ActivityLogger::attribution($this->owner))->toBe(['user_id' => $this->owner->id, 'agent_id' => $this->agent->id, 'via' => 'agent_run', 'agent_run_id' => $run->id])
            ->and($this->owner->agent()?->id)->toBe($this->agent->id)
            ->and($this->owner->viaToken())->toBeTrue()
            ->and($other->agent())->toBeNull();

        app(IssueService::class)->update($this->issue->fresh(), $this->owner, ['priority' => 'high']);
        app(AuditLogger::class)->log('test.run', $this->issue, actor: $this->owner);
    });

    expect(Activity::latest('id')->first()->only(['via', 'agent_id', 'agent_run_id']))->toBe(['via' => 'agent_run', 'agent_id' => $this->agent->id, 'agent_run_id' => $run->id])
        ->and(AuditLog::where('action', 'test.run')->sole()->only(['via', 'agent_id', 'agent_run_id']))->toBe(['via' => 'agent_run', 'agent_id' => $this->agent->id, 'agent_run_id' => $run->id])
        ->and($this->owner->agent())->toBeNull()
        ->and(ActivityLogger::attribution($this->owner))->toBe(['user_id' => $this->owner->id, 'agent_id' => null, 'via' => 'web']);

    // The binding goes even when the work throws.
    expect(fn () => ActivityLogger::forRun($run, fn () => throw new RuntimeException('x')))->toThrow(RuntimeException::class);
    expect($this->owner->viaToken())->toBeFalse();
});

test('tool gate: a tool outside the role, or with any made-up name, is refused and changes nothing', function () {
    $run = $this->runs->start($this->owner, $this->agent, $this->issue, ($this->role)('Writer'), null);
    $tools = app(RunTools::class);

    expect($tools->call($run, 'search_issues', ['q' => 'login'])['error'])->toBe('Unknown tool "search_issues". Tools you can use: read_issue, propose_fields.');
    foreach (['__construct', 'App\Services\IssueService::delete', 'delete_issue', 'readIssue', 'call', ''] as $name) {
        expect($tools->call($run, $name, ['key' => 'WEB-1']))->toHaveKey('error');
    }
    // A Writer proposes only summary and description.
    expect($tools->call($run, 'propose_fields', ['priority' => 'high'])['error'])->toBe('Writer can propose: title, description.')
        ->and($run->changes()->count())->toBe(0)
        ->and(RunTools::for(($this->role)('Writer')))->toHaveCount(2);
});

test('tool gate: arguments are validated, with the valid options listed', function () {
    $run = startTriage($this);
    $tools = app(RunTools::class);

    expect($tools->call($run, 'propose_fields', ['priority' => 'urgent!!'])['error'])->toBe('The priority must be one of: lowest, low, medium, high, highest.')
        ->and($tools->call($run, 'propose_fields', ['status' => 'Done'])['error'])->toBe('Triage can propose: type, priority, labels.')
        ->and($tools->call($run, 'propose_fields', [])['error'])->toBe('Propose at least one of: type, priority, labels.')
        ->and($tools->call($run, 'propose_link', ['type' => 'blocks', 'issue' => 'WEB-1'])['error'])->toBe('Triage can propose these links: duplicates, relates to.')
        ->and($tools->call($run, 'read_issue', [])['error'])->toBe('The key field is required.')
        ->and($run->changes()->count())->toBe(0);
});

test('tool gate: every call looks the issue up again as the agent, and checks the agent', function () {
    $ops = makeProject($this->owner, 'OPS');
    makeIssue($ops, $this->owner, ['title' => 'Hidden OPS plan']);
    [$scoped] = makeAgent($this->owner, AgentLevel::Write, ['WEB']);
    $run = $this->runs->start($this->owner, $scoped, $this->issue, ($this->role)('Triage'), null);
    $tools = app(RunTools::class);

    $error = $tools->call($run, 'read_issue', ['key' => 'OPS-1'])['error'];
    expect($error)->toBe('Project "OPS" was not found. Projects you can access: WEB.')
        ->and($error)->not->toContain('Hidden')
        ->and(json_encode($tools->call($run, 'search_issues', ['q' => 'Hidden'])))->not->toContain('Hidden')
        ->and($tools->call($run, 'read_issue', ['key' => 'WEB-1'])['key'])->toBe('WEB-1');

    // The run's own issue is looked up per call: once deleted, proposals stop.
    app(IssueService::class)->delete($this->issue, $this->owner);
    expect($tools->call($run, 'propose_fields', ['priority' => 'high']))->toHaveKey('error');

    app(AgentService::class)->pause($scoped, $this->owner);
    expect($tools->call($run, 'read_issue', ['key' => 'WEB-2'])['error'])->toContain('is paused');
});

test('child issues and links are proposed, never made', function () {
    $two = makeIssue($this->web, $this->owner, ['title' => 'Old login bug']);
    $run = $this->runs->start($this->owner, $this->agent, $this->issue, ($this->role)('Planner'), null);
    $tools = app(RunTools::class);

    expect($tools->call($run, 'propose_child_issue', ['title' => 'Fix the button', 'story_points' => 3]))->toHaveKey('proposed')
        ->and($tools->call($run, 'propose_link', ['type' => 'is blocked by', 'issue' => 'WEB-2']))->toHaveKey('proposed')
        ->and($tools->call($run, 'propose_link', ['type' => 'relates to', 'issue' => 'WEB-1'])['error'])->toBe('An issue can\'t link to itself.')
        ->and($run->changes()->orderBy('id')->get()->map(fn ($c) => [$c->kind, $c->target, $c->after])->all())->toEqual([
            ['child_issue', null, ['title' => 'Fix the button', 'story_points' => 3]],
            ['link', 'WEB-2', ['type' => 'is blocked by', 'issue' => 'WEB-2']],
        ])
        ->and($this->issue->fresh()->subtasks()->count())->toBe(0)
        ->and($two->fresh()->blockers()->count() + $this->issue->fresh()->blockers()->count())->toBe(0);
});

test('only the starter sees a run', function () {
    $run = startTriage($this);
    $admin = User::factory()->create();
    joinWorkspace($admin);

    expect(AgentRun::visibleTo($this->owner)->pluck('id')->all())->toBe([$run->id])
        ->and(AgentRun::visibleTo($admin)->count())->toBe(0);
});

test('prune: a run older than 30 days keeps who, what and tokens and drops its detail', function () {
    scriptAi([toolReply('propose_fields', ['priority' => 'high', 'type' => 'bug']), new AiReply('Done.', [], 5, 5)]);
    $old = runAgentNow($this->runs->start($this->owner, $this->agent, $this->issue, null, 'Triage this'));
    $old->changes()->where('target', 'type')->update(['status' => AgentRunChangeStatus::Applied]);
    $this->travel(31)->days();
    scriptAi([new AiReply('Done.', [], 5, 5)]);
    $recent = runAgentNow(startTriage($this));

    $this->artisan('agent-runs:prune')->assertSuccessful();

    $old->refresh();
    expect($old->steps)->toBeNull()
        ->and($old->instruction)->toBeNull()
        ->and([$old->user_id, $old->issue_id, $old->issue_key, $old->agent_id, $old->tokens_in, $old->tokens_out])
        ->toBe([$this->owner->id, $this->issue->id, 'WEB-1', $this->agent->id, 105, 25])
        ->and($old->started_at)->not->toBeNull()
        ->and($old->changes()->orderBy('id')->get()->map(fn ($c) => [$c->target, $c->before, $c->after])->all())->toEqual([
            ['type', ['value' => 'task'], ['value' => 'bug']], ['priority', null, []], [null, null, []],
        ])
        ->and($recent->fresh()->steps)->not->toBeNull();
});
