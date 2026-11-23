<?php

use App\Enums\AgentLevel;
use App\Models\ApprovalRequest;
use App\Models\Issue;
use App\Models\User;
use App\Services\ColumnService;
use App\Services\SprintService;

// "Statuses, not columns": done means the Done category wherever its column sits, never the last column.

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->web = makeProject($this->admin, 'WEB');
    $sprints = app(SprintService::class);
    $this->sprint = $sprints->create($this->web, ['name' => 'S1']);
    makeIssue($this->web, $this->admin, ['title' => 'done', 'status' => 'Done', 'sprint' => 'S1', 'story_points' => 3, 'due_date' => '2020-01-01']);
    makeIssue($this->web, $this->admin, ['title' => 'open', 'status' => 'In Review', 'sprint' => 'S1', 'story_points' => 5, 'due_date' => '2020-01-01']);
    makeIssue($this->web, $this->admin, ['title' => 'backlog done', 'status' => 'Done']);
    makeIssue($this->web, $this->admin, ['title' => 'backlog open']);
    $sprints->start($this->sprint->setRelation('project', $this->web), []);
    // Done first, In Review last: a position rule would now get everything wrong.
    $columns = app(ColumnService::class);
    $columns->update($this->web->columns()->where('name', 'Done')->sole()->setRelation('project', $this->web), ['position' => 0]);
    expect($this->web->columns()->pluck('name')->all())->toBe(['Done', 'To Do', 'In Progress', 'In Review']);
    apiAsPerson($this->admin);
});

test('sprint points, the backlog and dashboard counts use the Done category', function () {
    $this->getJson('/api/v1/projects/WEB/sprints/S1')->assertOk()->assertJsonPath('data.points_done', 3);
    $this->getJson('/api/v1/projects/WEB/backlog')->assertOk()->assertJsonPath('data.backlog.issues.*.title', ['backlog open']);
    $this->getJson('/api/v1/projects/WEB/summary')->assertOk()
        ->assertJsonPath('data.total', 4)
        ->assertJsonPath('data.open', 2)
        ->assertJsonPath('data.overdue', 1)
        ->assertJsonPath('data.statuses.0', ['name' => 'Done', 'count' => 2])
        ->assertJsonPath('data.active_sprint.issues_done', 1)
        ->assertJsonPath('data.active_sprint.points_done', 3);
});

test('completing a sprint carries over only issues whose category is not Done', function () {
    $this->postJson('/api/v1/projects/WEB/sprints/S1/complete', ['move_open_issues_to' => 'backlog', 'confirm' => true])->assertOk();

    expect(Issue::where('title', 'done')->sole()->sprint_id)->toBe($this->sprint->id)
        ->and(Issue::where('title', 'open')->sole()->sprint_id)->toBeNull();
});

test('my issues hide Done-category issues', function () {
    Issue::query()->update(['assignee_id' => $this->admin->id]);

    $this->getJson('/api/v1/me/issues')->assertOk()->assertJsonPath('data.*.title', fn ($titles) => collect($titles)->sort()->values()->all() === ['backlog open', 'open']);
});

test('an agent moving to a Done-category status in a middle column needs a person', function () {
    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'Shipped', 'category' => 'done', 'position' => 2])->assertCreated();
    apiAs($this->admin, AgentLevel::Write);

    $this->postJson('/api/v1/issues/WEB-4/move', ['status' => 'Shipped'])->assertStatus(202)->assertJsonPath('status', 'pending_approval')
        ->assertJsonPath('approval_request.to', 'Shipped');
    // 1-R5: from one Done status to another too.
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Shipped'])->assertStatus(202);
    // The last column holds an in-progress status: no approval.
    $this->postJson('/api/v1/issues/WEB-4/move', ['status' => 'In Review'])->assertOk()->assertJsonPath('data.status', 'In Review');

    expect(ApprovalRequest::count())->toBe(2)
        ->and(Issue::where('number', 1)->sole()->status->name)->toBe('Done');
});

test('A-L6: an agent cannot create an issue in a Done-category status, wherever its column is', function () {
    apiAs($this->admin, AgentLevel::Write);

    $this->postJson('/api/v1/projects/WEB/issues', ['title' => 'x', 'status' => 'Done'])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', "Agents can't create issues in Done. Create it in another status, then move it; a person approves the move.");
    $this->postJson('/api/v1/projects/WEB/issues', ['title' => 'y', 'status' => 'In Review'])->assertCreated();
});
