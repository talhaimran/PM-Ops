<?php

use App\Enums\AgentLevel;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Issue;
use App\Models\IssueStatusHistory;
use App\Models\Status;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\StatusService;
use Illuminate\Support\Facades\DB;

// Spec 7.4, 1-D5: merge one status into another of the same category.

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->web = makeProject($this->owner, 'WEB');
    $this->ws = $this->web->workspace;
    $this->statuses = app(StatusService::class);
    $this->st = fn (string $name) => Status::where('workspace_id', $this->ws->id)->where('name', $name)->sole();
    // "Review" is a second in-progress status, in the In Review column.
    $this->review = $this->statuses->create($this->ws, $this->owner, 'Review', StatusCategory::InProgress);
    $this->web->columns()->where('name', 'In Review')->sole()->statuses()->attach($this->review->id, ['position' => 1]);
    $this->into = ($this->st)('In Review');
    $this->merge = fn (Status $from, string $into) => $this->actingAs($this->owner)->from(w('/settings/statuses'))->post(w("/settings/statuses/{$from->id}/merge"), ['into' => $into]);
});

test('merging moves issues (soft-deleted too), column rows, history and pending approvals, then deletes the source', function () {
    $a = makeIssue($this->web, $this->owner, ['status' => 'Review']);
    $b = makeIssue($this->web, $this->owner, ['status' => 'Review']);
    $b->delete();
    makeIssue($this->web, $this->owner, ['status' => 'In Review']);
    $ops = makeProject($this->owner, 'OPS');
    $ops->columns()->where('name', 'In Review')->sole()->statuses()->attach($this->review->id, ['position' => 1]);
    $ops->columns()->where('name', 'In Review')->sole()->statuses()->detach($this->into->id);
    $c = makeIssue($ops, $this->owner, ['status' => 'Review']);

    [$agent] = makeAgent($this->owner, AgentLevel::Write);
    app(ApprovalService::class)->requestDone($a->load('project'), $this->owner, $agent, ($this->st)('Done'), 'ship it');
    $pending = ApprovalRequest::sole();
    $audits = AuditLog::where('action', 'status.merged')->count();

    ($this->merge)($this->review, 'In Review')->assertSessionHasNoErrors();

    expect(Status::find($this->review->id))->toBeNull()
        ->and(Issue::withTrashed()->where('status_id', $this->into->id)->count())->toBe(4)
        ->and(IssueStatusHistory::where('to_status_id', $this->into->id)->count())->toBe(4)
        ->and(IssueStatusHistory::whereNull('to_status_id')->count())->toBe(0)
        ->and(DB::table('column_statuses')->where('status_id', $this->review->id)->count())->toBe(0)
        ->and($this->web->columns()->where('name', 'In Review')->sole()->statuses()->pluck('name')->all())->toBe(['In Review'])
        ->and($ops->columns()->where('name', 'In Review')->sole()->statuses()->pluck('name')->all())->toBe(['In Review'])
        ->and($pending->fresh()->action['from_status_id'])->toBe($this->into->id)
        ->and(AuditLog::where('action', 'status.merged')->count())->toBe($audits + 1)
        ->and(AuditLog::where('action', 'status.merged')->latest('id')->first()->metadata)->toEqual(['from' => 'Review', 'into' => 'In Review', 'issues' => 3]);
});

test('a pending approval that targets the source is repointed to the survivor', function () {
    $done = $this->statuses->create($this->ws, $this->owner, 'Shipped', StatusCategory::Done);
    $this->web->columns()->where('name', 'Done')->sole()->statuses()->attach($done->id, ['position' => 1]);
    $issue = makeIssue($this->web, $this->owner);
    [$agent] = makeAgent($this->owner, AgentLevel::Write);
    app(ApprovalService::class)->requestDone($issue->load('project'), $this->owner, $agent, $done, 'x');

    ($this->merge)($done, 'Done')->assertSessionHasNoErrors();

    expect(ApprovalRequest::sole()->action)->toMatchArray(['to_status_id' => ($this->st)('Done')->id, 'to_status' => 'Done']);
});

test('a column that held both keeps one mapping row, for the survivor', function () {
    ($this->merge)($this->review, 'In Review')->assertSessionHasNoErrors();

    $col = $this->web->columns()->where('name', 'In Review')->sole();
    expect(DB::table('column_statuses')->where('column_id', $col->id)->get(['status_id', 'position'])->all())->toHaveCount(1);
});

test('different categories, the same status and another workspace are refused', function () {
    $this->actingAs($this->owner);
    ($this->merge)($this->review, 'Done')->assertSessionHasErrors(['status' => 'Review is In progress and Done is Done. Statuses merge only within the same category.']);
    ($this->merge)($this->review, 'review')->assertSessionHasErrors(['into' => 'Pick a different status to merge Review into.']);
    ($this->merge)($this->review, 'Nope')->assertSessionHasErrors('status');

    expect(Status::find($this->review->id))->not->toBeNull();
});

test('a project with the two in different columns refuses the merge and names it', function () {
    $ops = makeProject($this->owner, 'OPS');
    $qa = $ops->columns()->create(['name' => 'QA', 'position' => 4]);
    $qa->statuses()->attach($this->review->id, ['position' => 0]);

    ($this->merge)($this->review, 'In Review')
        ->assertSessionHasErrors(['status' => 'Review and In Review are in different columns of OPS. Put them in one column there first.']);
    expect(Status::find($this->review->id))->not->toBeNull();
});

test('a Member cannot merge', function () {
    $sam = User::factory()->create();
    $this->ws->addMember($sam, WorkspaceRole::Member);
    $this->actingAs($sam)->post(w("/settings/statuses/{$this->review->id}/merge"), ['into' => 'In Review'])->assertForbidden();
    expect(Status::find($this->review->id))->not->toBeNull();
});
