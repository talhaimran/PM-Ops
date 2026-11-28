<?php

use App\Enums\AgentLevel;
use App\Enums\AgentRunChangeStatus;
use App\Enums\AgentRunStatus;
use App\Enums\IssuePriority;
use App\Enums\ProjectRole;
use App\Http\Resources\AgentRunResource;
use App\Jobs\RunAgent;
use App\Models\Activity;
use App\Models\AgentRole;
use App\Models\AgentRun;
use App\Models\AgentRunChange;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\ProjectAgentSettings;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AgentRunService;
use App\Services\Ai\AiReply;
use App\Services\ChangeSetService;
use App\Services\IssueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Step B (B-D3, B-D6, B-R6..B-R8, B-R11, B-R17): applying and discarding what a run proposed, and the run web routes.

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create(['name' => 'Talha Imran']);
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->web = makeProject($this->owner, 'WEB');
    $this->issue = makeIssue($this->web, $this->owner, ['title' => 'Login fails', 'priority' => 'medium']);
    $this->other = makeIssue($this->web, $this->owner, ['title' => 'Signup fails']);
    [$this->agent] = makeAgent($this->owner, AgentLevel::Write, name: 'Helper');
    aiReady($this->ws);
    $this->runs = app(AgentRunService::class);
    $this->role = fn (string $name) => AgentRole::whereNull('workspace_id')->where('name', $name)->sole();
});

/** A finished typed-instruction run that proposed two fields, a child issue, a link and (its reply) a comment. */
function proposingRun($t, ?User $starter = null, $agent = null): AgentRun
{
    scriptAi([
        toolReply('propose_fields', ['title' => 'Sign in button does nothing', 'priority' => 'high']),
        toolReply('propose_child_issue', ['title' => 'Add a login test', 'story_points' => 2]),
        toolReply('propose_link', ['type' => 'relates to', 'issue' => 'WEB-2']),
        new AiReply('Retitled it and raised the priority.', [], 10, 5),
    ]);

    return runAgentNow($t->runs->start($starter ?? $t->owner, $agent ?? $t->agent, $t->issue, null, 'Tidy this up.'));
}

function changeOf(AgentRun $run, string $kind, ?string $target = null): AgentRunChange
{
    return $run->changes()->where('kind', $kind)->when($target, fn ($q) => $q->where('target', $target))->sole();
}

test('apply all writes every change through the services, as the starter with the agent and the run linked', function () {
    $run = proposingRun($this);
    expect($run->changes()->where('status', AgentRunChangeStatus::Proposed)->count())->toBe(5);

    $this->actingAs($this->owner)->post(w("/runs/{$run->id}/apply"))->assertRedirect()->assertSessionHasNoErrors();

    $issue = $this->issue->fresh();
    $child = Issue::where('parent_id', $issue->id)->sole();
    $comment = Comment::where('issue_id', $issue->id)->sole();
    expect($issue->only(['title', 'priority']))->toEqual(['title' => 'Sign in button does nothing', 'priority' => IssuePriority::High])
        ->and($child->only(['title', 'story_points', 'reporter_id']))->toEqual(['title' => 'Add a login test', 'story_points' => 2, 'reporter_id' => $this->owner->id])
        ->and(IssueLink::where('source_issue_id', $issue->id)->where('target_issue_id', $this->other->id)->value('type')?->value)->toBe('relates to')
        ->and($comment->only(['body', 'user_id', 'agent_id']))->toEqual(['body' => 'Retitled it and raised the priority.', 'user_id' => $this->owner->id, 'agent_id' => $this->agent->id])
        ->and($run->changes()->pluck('status')->unique()->all())->toEqual([AgentRunChangeStatus::Applied]);

    // B-R7/B-R11: normal edits, by the starter on the web, naming the agent and the run.
    $rows = Activity::where('agent_run_id', $run->id)->get();
    expect($rows->pluck('action')->sort()->values()->all())->toEqual(['commented', 'created', 'linked', 'linked', 'updated', 'updated'])
        ->and($rows->map->only(['user_id', 'agent_id', 'via'])->unique()->values()->all())->toEqual([['user_id' => $this->owner->id, 'agent_id' => $this->agent->id, 'via' => 'web']]);
});

test('apply one and discard one; applied or discarded changes can\'t be applied again', function () {
    $run = proposingRun($this);
    $priority = changeOf($run, 'field', 'priority');
    $title = changeOf($run, 'field', 'title');
    $this->actingAs($this->owner);

    $this->post(w("/runs/{$run->id}/changes/{$priority->id}/apply"))->assertSessionHasNoErrors();
    $this->post(w("/runs/{$run->id}/changes/{$title->id}/discard"))->assertSessionHasNoErrors();
    expect($this->issue->fresh()->only(['title', 'priority']))->toEqual(['title' => 'Login fails', 'priority' => IssuePriority::High])
        ->and($priority->fresh()->status)->toBe(AgentRunChangeStatus::Applied)
        ->and($title->fresh()->status)->toBe(AgentRunChangeStatus::Discarded);

    $this->post(w("/runs/{$run->id}/changes/{$priority->id}/apply"))->assertSessionHasErrors(['change' => 'This change was already applied.']);
    $this->post(w("/runs/{$run->id}/changes/{$title->id}/apply"))->assertSessionHasErrors(['change' => 'This change was already discarded.']);
    $this->post(w("/runs/{$run->id}/changes/{$priority->id}/discard"))->assertSessionHasErrors(['change' => 'This change was already applied.']);

    // Discard all leaves what was applied; apply all then has nothing left.
    $this->post(w("/runs/{$run->id}/discard"))->assertSessionHasNoErrors();
    expect($run->changes()->pluck('status', 'id')->countBy(fn ($s) => $s->value)->all())->toEqual(['applied' => 1, 'discarded' => 4])
        ->and(app(ChangeSetService::class)->apply($run, $this->owner))->toEqual(['applied' => 0, 'conflicts' => []])
        ->and($this->issue->fresh()->title)->toBe('Login fails');
});

test('Review Focus 3: a field someone changed after the proposal is a conflict saying what changed; the rest are applied', function () {
    $run = proposingRun($this);
    $sam = User::factory()->create(['name' => 'Sam Lee']);
    joinWorkspace($sam);
    addMember($this->web, $sam, ProjectRole::Member);
    $issue = $this->issue->fresh();
    app(IssueService::class)->update($issue, $sam, ['priority' => 'low', 'version' => $issue->version]);

    $result = app(ChangeSetService::class)->apply($run, $this->owner);

    $priority = changeOf($run, 'field', 'priority');
    expect($result['applied'])->toBe(4)
        ->and($result['conflicts'])->toEqual([['id' => $priority->id, 'message' => 'Sam Lee changed priority on WEB-1 since this was proposed.']])
        ->and($priority->status)->toBe(AgentRunChangeStatus::Conflict)
        ->and($priority->message)->toBe('Sam Lee changed priority on WEB-1 since this was proposed.')
        ->and($this->issue->fresh()->only(['title', 'priority']))->toEqual(['title' => 'Sign in button does nothing', 'priority' => IssuePriority::Low]);

    // A conflict can be discarded, not applied.
    $this->actingAs($this->owner)->post(w("/runs/{$run->id}/changes/{$priority->id}/apply"))->assertSessionHasErrors(['change' => 'This change is in conflict. Discard it, or start a new run to propose it again.']);
    $this->post(w("/runs/{$run->id}/changes/{$priority->id}/discard"))->assertSessionHasNoErrors();
    expect($priority->fresh()->status)->toBe(AgentRunChangeStatus::Discarded);
});

test('a field change whose history since the proposal is cut off is a conflict', function () {
    $run = proposingRun($this);
    $issue = $this->issue->fresh();
    $row = ['issue_id' => $issue->id, 'action' => 'updated', 'changes' => json_encode(['story_points' => ['from' => null, 'to' => 1]]), 'via' => 'web', 'created_at' => now()];
    Activity::insert(array_map(fn ($v) => [...$row, 'issue_version' => $v], range($issue->version + 1, $issue->version + ActivityLogger::CHANGES_CAP + 1)));
    $issue->forceFill(['version' => $issue->version + ActivityLogger::CHANGES_CAP + 1])->saveQuietly();

    $result = app(ChangeSetService::class)->apply($run, $this->owner, $priority = changeOf($run, 'field', 'priority'));

    expect($result['conflicts'])->toEqual([['id' => $priority->id, 'message' => 'WEB-1 changed too much since this was proposed to check this field. Discard it, or start a new run to propose it again.']])
        ->and($this->issue->fresh()->priority)->toBe(IssuePriority::Medium);
});

test('a link to an issue that is gone, or a child the issue can\'t take, is a conflict with the reason', function () {
    $run = proposingRun($this);
    app(IssueService::class)->delete($this->other, $this->owner);
    app(IssueService::class)->update($this->issue->fresh(), $this->owner, ['type' => 'epic']);

    $result = app(ChangeSetService::class)->apply($run, $this->owner);

    expect(changeOf($run, 'link')->only(['status', 'message']))->toEqual(['status' => AgentRunChangeStatus::Conflict, 'message' => 'Issue WEB-2 was not found. Search the project\'s issues to find the right key.'])
        ->and(changeOf($run, 'child_issue')->status)->toBe(AgentRunChangeStatus::Applied)
        ->and(Issue::where('epic_id', $this->issue->id)->value('title'))->toBe('Add a login test')
        ->and(collect($result['conflicts'])->pluck('id')->all())->toBe([changeOf($run, 'link')->id]);
});

test('B-D6: another person, even a project Admin and workspace Owner, gets "not found" on every run route', function () {
    $bob = User::factory()->create();
    joinWorkspace($bob);
    addMember($this->web, $bob, ProjectRole::Member);
    [$bobsAgent] = makeAgent($bob, AgentLevel::Write);
    $run = proposingRun($this, $bob, $bobsAgent);
    $change = changeOf($run, 'field', 'title');
    $notFound = ['run' => "Run {$run->id} was not found."];

    $this->actingAs($this->owner);
    foreach (['cancel', 'apply', 'discard', "changes/{$change->id}/apply", "changes/{$change->id}/discard"] as $action) {
        $this->post(w("/runs/{$run->id}/{$action}"))->assertSessionHasErrors($notFound);
    }
    expect($run->changes()->pluck('status')->unique()->all())->toEqual([AgentRunChangeStatus::Proposed])
        ->and($this->issue->fresh()->title)->toBe('Login fails');

    // A change of another run, named under the starter's own run, is not found either.
    $mine = proposingRun($this);
    $this->post(w("/runs/{$mine->id}/changes/{$change->id}/apply"))->assertSessionHasErrors(['change' => "Change {$change->id} was not found. Open the run to see its changes."]);
});

test('a starter who lost the project gets "not found"; a starter now a Viewer is refused', function () {
    $bob = User::factory()->create();
    joinWorkspace($bob);
    addMember($this->web, $bob, ProjectRole::Member);
    [$bobsAgent] = makeAgent($bob, AgentLevel::Write);
    $run = proposingRun($this, $bob, $bobsAgent);
    $this->actingAs($bob);

    $this->web->members()->updateExistingPivot($bob->id, ['role' => ProjectRole::Viewer->value]);
    $this->post(w("/runs/{$run->id}/apply"))->assertForbidden();
    $this->post(w("/runs/{$run->id}/changes/".changeOf($run, 'comment')->id.'/apply'))->assertForbidden();

    $this->web->members()->detach($bob->id);
    $gone = ['issue' => 'WEB-1 was deleted, or you can no longer see it.'];
    $this->post(w("/runs/{$run->id}/apply"))->assertSessionHasErrors($gone);
    $this->post(w("/runs/{$run->id}/discard"))->assertSessionHasErrors($gone);
    expect($run->changes()->pluck('status')->unique()->all())->toEqual([AgentRunChangeStatus::Proposed])
        ->and(Comment::count())->toBe(0);
});

test('panel apply still works after the project\'s Mode C was turned off: it is the starter\'s own edit', function () {
    $run = proposingRun($this);
    ProjectAgentSettings::updateOrCreate(['project_id' => $this->web->id], ['mode_c_enabled' => false]);

    expect(app(ChangeSetService::class)->apply($run, $this->owner, changeOf($run, 'field', 'title')))->toEqual(['applied' => 1, 'conflicts' => []])
        ->and($this->issue->fresh()->title)->toBe('Sign in button does nothing');
});

test('B-R6/B-R1: a role switched to apply applies in the job as the agent; a Read-level agent and a typed instruction only propose', function () {
    $triage = ($this->role)('Triage');
    $this->ws->forceFill(['settings' => [...($this->ws->settings ?? []), 'agent_role_apply' => [$triage->id => 'apply']]])->save();
    scriptAi([toolReply('propose_fields', ['priority' => 'high']), new AiReply('Raised it.', [], 1, 1)]);
    // Fix round 1: the final comment is applied after end()'s transaction, at the same depth as the field change.
    $depth = [];
    Activity::created(function (Activity $a) use (&$depth) {
        $depth[$a->action] = DB::transactionLevel();
    });

    $run = runAgentNow($this->runs->start($this->owner, $this->agent, $this->issue, $triage, null));

    expect($depth['commented'])->toBe($depth['updated'])
        ->and($run->status)->toBe(AgentRunStatus::Finished)
        ->and($run->changes()->pluck('status')->unique()->all())->toEqual([AgentRunChangeStatus::Applied])
        ->and($this->issue->fresh()->priority)->toBe(IssuePriority::High)
        ->and(Comment::sole()->only(['user_id', 'agent_id']))->toEqual(['user_id' => $this->owner->id, 'agent_id' => $this->agent->id])
        ->and(Activity::where('agent_run_id', $run->id)->get()->map->only(['user_id', 'agent_id', 'via'])->unique()->values()->all())
        ->toEqual([['user_id' => $this->owner->id, 'agent_id' => $this->agent->id, 'via' => 'agent_run']]);

    [$reader] = makeAgent($this->owner, AgentLevel::Read);
    scriptAi([toolReply('propose_fields', ['priority' => 'low']), new AiReply('Lowered it.', [], 1, 1)]);
    $readRun = runAgentNow($this->runs->start($this->owner, $reader, $this->issue, $triage, null));
    $typed = proposingRun($this);

    expect($readRun->changes()->pluck('status')->unique()->all())->toEqual([AgentRunChangeStatus::Proposed])
        ->and($typed->changes()->pluck('status')->unique()->all())->toEqual([AgentRunChangeStatus::Proposed])
        ->and($this->issue->fresh()->priority)->toBe(IssuePriority::High);
});

test('a cancel landing during a propose call stops its direct apply', function () {
    $triage = ($this->role)('Triage');
    $this->ws->forceFill(['settings' => [...($this->ws->settings ?? []), 'agent_role_apply' => [$triage->id => 'apply']]])->save();
    scriptAi([toolReply('propose_fields', ['priority' => 'high'])]);
    $run = $this->runs->start($this->owner, $this->agent, $this->issue, $triage, null);
    AgentRunChange::created(fn () => $this->runs->cancel($run, $this->owner));

    $run = runAgentNow($run);

    expect($run->status)->toBe(AgentRunStatus::Cancelled)
        ->and($run->changes()->pluck('status')->all())->toEqual([AgentRunChangeStatus::Proposed])
        ->and($this->issue->fresh()->priority)->toBe(IssuePriority::Medium);
});

test('a custom role\'s apply_mode switches direct apply', function () {
    $role = AgentRole::create(['workspace_id' => $this->ws->id, 'name' => 'Fixer', 'instructions' => 'Fix it.', 'allowed_tools' => ['propose_fields'], 'apply_mode' => 'apply']);
    scriptAi([toolReply('propose_fields', ['title' => 'Better title']), new AiReply('', [], 1, 1)]);

    runAgentNow($this->runs->start($this->owner, $this->agent, $this->issue, $role, null));

    expect($this->issue->fresh()->title)->toBe('Better title');
});

test('the start route queues a run; it needs a role or an instruction and one of your own agents', function () {
    $triage = ($this->role)('Triage');
    $this->actingAs($this->owner);

    $this->post(w('/issues/WEB-1/runs'), ['agent' => $this->agent->id, 'role' => $triage->id])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(w('/issues/WEB-1/runs'), ['agent' => $this->agent->id, 'instruction' => 'Summarise it.'])->assertSessionHasNoErrors();
    expect(AgentRun::orderBy('id')->get()->map->only(['role_name', 'instruction', 'user_id'])->all())->toEqual([
        ['role_name' => 'Triage', 'instruction' => null, 'user_id' => $this->owner->id],
        ['role_name' => 'Instruction', 'instruction' => 'Summarise it.', 'user_id' => $this->owner->id],
    ]);
    Queue::assertPushed(RunAgent::class, 2);

    $this->post(w('/issues/WEB-1/runs'), ['agent' => $this->agent->id])->assertSessionHasErrors(['instruction' => 'Pick a role or type an instruction.']);
    $this->post(w('/issues/WEB-1/runs'), ['agent' => $this->agent->id, 'instruction' => str_repeat('x', 4001)])->assertSessionHasErrors('instruction');
    $this->post(w('/issues/WEB-1/runs'), ['agent' => $this->agent->id, 'role' => 999999])
        ->assertSessionHasErrors(['role' => 'That role was not found. Roles: Planner, Summariser, Triage, Writer.']);
    $bob = User::factory()->create();
    joinWorkspace($bob);
    [$bobsAgent] = makeAgent($bob, AgentLevel::Write);
    $this->post(w('/issues/WEB-1/runs'), ['agent' => $bobsAgent->id, 'role' => $triage->id])->assertSessionHasErrors(['agent' => 'Choose one of your own agents: Helper.']);
    expect(AgentRun::count())->toBe(2);
});

test('the starter cancels their run from the web', function () {
    $run = $this->runs->start($this->owner, $this->agent, $this->issue, ($this->role)('Triage'), null);

    $this->actingAs($this->owner)->post(w("/runs/{$run->id}/cancel"))->assertRedirect()->assertSessionHasNoErrors();

    expect($run->fresh()->status)->toBe(AgentRunStatus::Cancelled);
});

test('AgentRunResource: the run panel\'s shape, never the instruction, key or endpoint', function () {
    $run = proposingRun($this);
    $change = changeOf($run, 'field', 'priority');

    $data = (new AgentRunResource($run->load(['agent', 'changes'])))->resolve();

    expect(array_keys($data))->toBe(['id', 'status', 'role_name', 'agent', 'steps', 'tokens_in', 'tokens_out', 'error', 'changes'])
        ->and($data['agent'])->toBe('Helper')
        ->and($data['status'])->toBe('finished')
        ->and(collect($data['changes'])->firstWhere('id', $change->id))->toEqual([
            'id' => $change->id, 'kind' => 'field', 'target' => 'priority', 'before' => ['value' => 'medium'], 'after' => ['value' => 'high'], 'status' => 'proposed', 'message' => null,
        ])
        ->and(json_encode($data))->not->toContain('Tidy this up.')->not->toContain('sk-RUN-SECRET');
});
