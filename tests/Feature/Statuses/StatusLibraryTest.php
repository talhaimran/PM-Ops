<?php

use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\MoveIssue;
use App\Models\AuditLog;
use App\Models\Status;
use App\Models\User;

// Spec 7.4: statuses are a workspace library; columns map to them; issues carry a status. People-only column calls.

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->web = makeProject($this->admin, 'WEB');
    apiAsPerson($this->admin);
});

$statuses = fn ($column) => test()->getJson('/api/v1/projects/WEB/columns')->collect('data')->firstWhere('name', $column)['statuses'];

test('a new project maps its four default columns to library statuses', function () use ($statuses) {
    expect($statuses('To Do'))->toBe([['name' => 'To Do', 'category' => 'to_do', 'color' => null]])
        ->and($statuses('In Progress'))->toBe([['name' => 'In Progress', 'category' => 'in_progress', 'color' => null]])
        ->and($statuses('In Review'))->toBe([['name' => 'In Review', 'category' => 'in_progress', 'color' => null]])
        ->and($statuses('Done'))->toBe([['name' => 'Done', 'category' => 'done', 'color' => null]]);

    // A second project reuses the same rows.
    makeProject($this->admin, 'OPS');
    expect(Status::where('workspace_id', $this->web->workspace_id)->count())->toBe(4);
});

test('a new column creates its in-progress status once and logs status.created', function () use ($statuses) {
    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'QA'])->assertCreated()
        ->assertJsonPath('data.statuses', [['name' => 'QA', 'category' => 'in_progress', 'color' => null]]);

    expect($statuses('QA'))->toBe([['name' => 'QA', 'category' => 'in_progress', 'color' => null]])
        ->and(AuditLog::where('action', 'status.created')->where('target_label', 'QA')->sole()->only(['target_type', 'target_label', 'actor_user_id', 'metadata']))
        ->toEqual(['target_type' => 'status', 'target_label' => 'QA', 'actor_user_id' => $this->admin->id, 'metadata' => ['name' => 'QA', 'category' => 'in_progress']]);

    // Another project's QA column reuses it.
    makeProject($this->admin, 'OPS');
    $this->postJson('/api/v1/projects/OPS/columns', ['name' => 'QA'])->assertCreated();
    expect(AuditLog::where('action', 'status.created')->where('target_label', 'QA')->count())->toBe(1);
});

test('1-R4: a column can map an existing status or take a category', function () {
    $audits = AuditLog::max('id');
    $this->deleteJson('/api/v1/projects/WEB/columns/Done')->assertNoContent();
    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'Shipped', 'status' => 'done'])->assertCreated()
        ->assertJsonPath('data.statuses', [['name' => 'Done', 'category' => 'done', 'color' => null]]);
    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'Released', 'category' => 'done'])->assertCreated()
        ->assertJsonPath('data.statuses.0.category', 'done');

    expect(AuditLog::where('id', '>', $audits)->where('action', 'status.created')->pluck('target_label')->all())->toBe(['Released']);
    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'X', 'category' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('category');
    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'X', 'status' => 'Nope'])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', fn ($m) => str_starts_with($m, 'Unknown status "Nope".') && str_contains($m, 'Done, In Progress'));
});

test('a status already in another column of the project is refused, naming that column', function () {
    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'Shipped', 'status' => 'Done'])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Done is already in the Done column of WEB. A status can be in one column per project.');

    expect($this->web->columns()->count())->toBe(4);
});

test('a name clash with another category gets the project key', function () {
    $this->deleteJson('/api/v1/projects/WEB/columns/Done')->assertNoContent();
    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'Done', 'category' => 'to_do'])->assertCreated()
        ->assertJsonPath('data.statuses', [['name' => 'Done (WEB)', 'category' => 'to_do', 'color' => null]]);
});

test('an issue takes a status name, or a column name when that column has one status', function () {
    makeIssue($this->web, $this->admin);

    $this->patchJson('/api/v1/issues/WEB-1', ['status' => 'In Review'])->assertOk()
        ->assertJsonPath('data.status', 'In Review')->assertJsonPath('data.category', 'in_progress');
    $this->patchJson('/api/v1/projects/WEB/columns/In Review', ['name' => 'Review'])->assertOk();
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Review'])->assertOk()->assertJsonPath('data.status', 'In Review');
    PmServer::tool(MoveIssue::class, ['issue' => 'WEB-1', 'status' => 'done'])->assertOk()->assertSee(['Moved WEB-1 to Done', '"category":"done"']);

    $this->patchJson('/api/v1/issues/WEB-1', ['status' => 'Nope'])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Unknown status "Nope". Valid statuses for WEB: To Do, In Progress, In Review, Done.');
});

test('1-R3: renaming a column leaves the status and the issue unchanged', function () {
    makeIssue($this->web, $this->admin, ['status' => 'In Progress']);

    $this->patchJson('/api/v1/projects/WEB/columns/In Progress', ['name' => 'Doing'])->assertOk()
        ->assertJsonPath('data.statuses.0.name', 'In Progress');

    $this->getJson('/api/v1/issues/WEB-1')->assertJsonPath('data.status', 'In Progress');
    expect(Status::where('name', 'Doing')->exists())->toBeFalse();
});

test("deleting a column moves its issues, soft-deleted ones too, to the target column's first status", function () {
    makeIssue($this->web, $this->admin, ['status' => 'In Review']);
    $gone = makeIssue($this->web, $this->admin, ['status' => 'In Review']);
    $gone->delete();

    $this->deleteJson('/api/v1/projects/WEB/columns/In Review', ['move_issues_to' => 'In Progress'])->assertNoContent();

    $this->getJson('/api/v1/issues/WEB-1')->assertJsonPath('data.status', 'In Progress')->assertJsonPath('data.category', 'in_progress');
    expect($gone->fresh()->status->name)->toBe('In Progress')
        // The status stays in the library, unmapped from WEB.
        ->and(Status::where('name', 'In Review')->exists())->toBeTrue();
});

test('board, list and issue responses carry status and category', function () {
    makeIssue($this->web, $this->admin, ['status' => 'Done']);

    $this->getJson('/api/v1/projects/WEB/board')->assertOk()
        ->assertJsonPath('data.columns.3.statuses', [['name' => 'Done', 'category' => 'done', 'color' => null]])
        ->assertJsonPath('data.columns.3.issues.0.status', 'Done')
        ->assertJsonPath('data.columns.3.issues.0.category', 'done');
    $this->getJson('/api/v1/projects/WEB/issues')->assertJsonPath('data.0.category', 'done');
    $this->getJson('/api/v1/issues/WEB-1')->assertJsonPath('data.category', 'done');
});
