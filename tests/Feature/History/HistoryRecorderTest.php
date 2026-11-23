<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Models\EstimateHistory;
use App\Models\Issue;
use App\Models\IssueStatusHistory;
use App\Models\SprintScopeEvent;
use App\Models\User;
use App\Services\ColumnService;
use App\Services\IssueService;
use App\Services\SprintService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = makeProject($this->owner, 'WEB');
    $this->sprints = app(SprintService::class);
    $this->issues = app(IssueService::class);
    $this->sprint = fn (string $name) => $this->sprints->create($this->project, ['name' => $name]);
});

test('creating an issue writes a status row, a joined row in its sprint and an estimate row', function () {
    ($this->sprint)('S1');
    $issue = makeIssue($this->project, $this->owner, ['status' => 'In Progress', 'sprint' => 'S1', 'story_points' => 5]);

    $status = IssueStatusHistory::sole();
    expect($status->issue_id)->toBe($issue->id)->and($status->from_status_id)->toBeNull()->and($status->to_status_id)->toBe($issue->status_id)
        ->and($status->to_category)->toBe('in_progress')->and($status->user_id)->toBe($this->owner->id)->and($status->via)->toBe('web')->and($status->agent_id)->toBeNull();
    $joined = SprintScopeEvent::sole();
    expect($joined->event)->toBe('joined')->and($joined->estimate)->toBe(5)->and($joined->sprint_id)->toBe($issue->sprint_id);
    $estimate = EstimateHistory::sole();
    expect($estimate->from_estimate)->toBeNull()->and($estimate->to_estimate)->toBe(5);
});

test('a plain issue writes one status row and no sprint or estimate rows', function () {
    makeIssue($this->project, $this->owner);

    expect(IssueStatusHistory::count())->toBe(1)->and(SprintScopeEvent::count())->toBe(0)->and(EstimateHistory::count())->toBe(0);
});

test('a status change writes one row with the category; the same status writes none', function () {
    $issue = makeIssue($this->project, $this->owner);
    $from = $issue->status_id;

    $this->issues->update($issue, $this->owner, ['status' => 'Done']);
    $this->issues->update($issue->refresh(), $this->owner, ['status' => 'Done', 'title' => 'renamed']);

    $row = IssueStatusHistory::latest('id')->first();
    expect(IssueStatusHistory::count())->toBe(2)->and($row->from_status_id)->toBe($from)->and($row->to_category)->toBe('done')
        ->and($row->to_status_id)->toBe($issue->refresh()->status_id);
});

test('moving sprint A to B writes left A and joined B with the current estimate', function () {
    [$a, $b] = [($this->sprint)('A'), ($this->sprint)('B')];
    $issue = makeIssue($this->project, $this->owner, ['sprint' => 'A', 'story_points' => 3]);

    $this->issues->update($issue, $this->owner, ['sprint' => 'B']);

    $events = SprintScopeEvent::orderBy('id')->get();
    expect($events->pluck('event')->all())->toBe(['joined', 'left', 'joined'])
        ->and($events[1]->sprint_id)->toBe($a->id)->and($events[2]->sprint_id)->toBe($b->id)
        ->and($events[1]->estimate)->toBe(3)->and($events[2]->estimate)->toBe(3);

    $this->issues->update($issue->refresh(), $this->owner, ['sprint' => null]);
    expect(SprintScopeEvent::latest('id')->first()->event)->toBe('left')->and(SprintScopeEvent::count())->toBe(4);
});

test('re-estimating writes from and to; an unchanged estimate writes none', function () {
    $issue = makeIssue($this->project, $this->owner, ['story_points' => 3]);

    $this->issues->update($issue, $this->owner, ['story_points' => 8]);
    $this->issues->update($issue->refresh(), $this->owner, ['story_points' => 8]);
    $this->issues->update($issue->refresh(), $this->owner, ['story_points' => null]);

    expect(EstimateHistory::orderBy('id')->get(['from_estimate', 'to_estimate'])->map->only('from_estimate', 'to_estimate')->all())
        ->toBe([['from_estimate' => null, 'to_estimate' => 3], ['from_estimate' => 3, 'to_estimate' => 8], ['from_estimate' => 8, 'to_estimate' => null]]);
});

test('completing a sprint writes left and joined for each carried-over issue, and left alone to the backlog', function () {
    [$a, $b] = [($this->sprint)('A'), ($this->sprint)('B')];
    $open = makeIssue($this->project, $this->owner, ['sprint' => 'A', 'story_points' => 2]);
    $done = makeIssue($this->project, $this->owner, ['sprint' => 'A', 'status' => 'Done']);
    $this->sprints->start($a, []);
    SprintScopeEvent::query()->delete();

    $this->sprints->complete($a->refresh(), $this->owner, 'B');

    $events = SprintScopeEvent::all();
    expect($events)->toHaveCount(2)->and($events->every(fn ($e) => $e->issue_id === $open->id && $e->estimate === 2 && $e->via === 'web' && $e->user_id === $this->owner->id))->toBeTrue()
        ->and($events->firstWhere('event', 'left')->sprint_id)->toBe($a->id)->and($events->firstWhere('event', 'joined')->sprint_id)->toBe($b->id);

    $this->sprints->start($b->refresh(), []);
    SprintScopeEvent::query()->delete();
    $this->sprints->complete($b->refresh(), $this->owner, 'backlog');
    expect(SprintScopeEvent::sole()->event)->toBe('left');
});

test('deleting a planned sprint takes its scope events with it (FK cascade; a planned sprint has no report)', function () {
    $sprint = ($this->sprint)('A');
    makeIssue($this->project, $this->owner, ['sprint' => 'A', 'story_points' => 4]);
    expect(SprintScopeEvent::where('sprint_id', $sprint->id)->count())->toBe(1);

    $this->sprints->delete($sprint);

    expect(SprintScopeEvent::where('sprint_id', $sprint->id)->count())->toBe(0);
});

test('a sprint change and a re-estimate in one update: left has the old estimate, joined the new', function () {
    [$a, $b] = [($this->sprint)('A'), ($this->sprint)('B')];
    $issue = makeIssue($this->project, $this->owner, ['sprint' => 'A', 'story_points' => 5]);

    $this->issues->update($issue, $this->owner, ['sprint' => 'B', 'story_points' => 8]);

    $events = SprintScopeEvent::orderBy('id')->get()->slice(1)->values();
    expect($events->map->only('event', 'sprint_id', 'estimate')->all())->toBe([['event' => 'left', 'sprint_id' => $a->id, 'estimate' => 5], ['event' => 'joined', 'sprint_id' => $b->id, 'estimate' => 8]]);
});

test('deleting a column writes a status row for each moved issue', function () {
    $issue = makeIssue($this->project, $this->owner, ['status' => 'In Review']);
    $before = $issue->status_id;
    $column = $this->project->columns()->whereRelation('statuses', 'statuses.id', $before)->sole();
    IssueStatusHistory::query()->delete();

    app(ColumnService::class)->delete($column, 'Done', $this->owner);

    $row = IssueStatusHistory::sole();
    expect($row->issue_id)->toBe($issue->id)->and($row->from_status_id)->toBe($before)->and($row->to_category)->toBe('done')->and($row->to_status_id)->toBe($issue->refresh()->status_id);
});

test('deleting a status that history points at keeps the row and its category (1-R9)', function () {
    $issue = makeIssue($this->project, $this->owner);
    $statusId = $issue->status_id;
    $this->issues->update($issue, $this->owner, ['status' => 'Done']);
    DB::table('issues')->where('id', $issue->id)->update(['status_id' => $issue->refresh()->status_id]);
    DB::table('column_statuses')->where('status_id', $statusId)->delete();
    DB::table('statuses')->where('id', $statusId)->delete();

    $first = IssueStatusHistory::orderBy('id')->first();
    expect($first->to_status_id)->toBeNull()->and($first->to_category)->toBe('to_do')->and(IssueStatusHistory::count())->toBe(2);
});

test('rows made over REST carry via api and the agent; over MCP via mcp; on the web via web', function () {
    makeIssue($this->project, $this->owner);
    makeIssue($this->project, $this->owner);
    makeIssue($this->project, $this->owner);
    [$agent, $token] = makeAgent($this->owner, AgentLevel::Write);
    IssueStatusHistory::query()->delete();

    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['status' => 'In Progress', 'story_points' => 2])->assertOk();
    $this->actingAs($this->owner)->post(w('/issues/WEB-3/move'), ['status' => 'In Progress'])->assertSessionHasNoErrors();
    // Last: the MCP call leaves its via binding in the container for the rest of the test.
    mcpJson($token, 'update_issue', ['issue' => 'WEB-2', 'status' => 'In Progress', 'story_points' => 3]);

    $byIssue = fn (string $model, int $n) => $model::where('issue_id', Issue::where('number', $n)->value('id'))->get();
    expect($byIssue(IssueStatusHistory::class, 1)->sole()->only('via', 'agent_id', 'user_id'))->toBe(['via' => 'api', 'agent_id' => $agent->id, 'user_id' => $this->owner->id])
        ->and($byIssue(EstimateHistory::class, 1)->sole()->only('via', 'agent_id'))->toBe(['via' => 'api', 'agent_id' => $agent->id])
        ->and($byIssue(IssueStatusHistory::class, 2)->sole()->only('via', 'agent_id'))->toBe(['via' => 'mcp', 'agent_id' => $agent->id])
        ->and($byIssue(IssueStatusHistory::class, 3)->sole()->only('via', 'agent_id'))->toBe(['via' => 'web', 'agent_id' => null]);
});

test('a held agent move writes no row; the approved move has the decider as user and the agent as agent', function () {
    makeIssue($this->project, $this->owner);
    [$agent, $token] = makeAgent($this->owner, AgentLevel::Write);
    IssueStatusHistory::query()->delete();

    $id = asToken($token)->patchJson('/api/v1/issues/WEB-1', ['status' => 'Done'])->assertStatus(202)->json('approval_request.id');
    expect(IssueStatusHistory::count())->toBe(0);

    $decider = User::factory()->create();
    addMember($this->project, $decider, ProjectRole::Admin);
    test()->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($decider)->post(w("/approvals/{$id}/approve"))->assertSessionHasNoErrors();

    $row = IssueStatusHistory::sole();
    expect($row->user_id)->toBe($decider->id)->and($decider->id)->not->toBe($this->owner->id)->and($row->agent_id)->toBe($agent->id)->and($row->to_category)->toBe('done')->and($row->via)->toBe('web');
});
