<?php

use App\Enums\AgentLevel;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\BulkUpdateIssues;
use App\Mcp\Tools\MoveIssue;
use App\Mcp\Tools\UpdateIssue;
use App\Models\ApprovalRequest;
use App\Models\Issue;
use App\Models\User;
use App\Services\LinkService;

// 2-D4: moving a blocked issue into an In Progress-category status, or starting a successor whose predecessor is
// unresolved, warns. Web: a confirm first. REST and MCP: 422 with the warning until resent with confirm: true.

const BLOCKED = "WEB-15 is blocked by WEB-12 (In Progress), which isn't resolved. Send confirm: true to move it anyway.";

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->web = makeProject($this->admin, 'WEB');
    foreach (range(1, 15) as $n) {
        makeIssue($this->web, $this->admin, ['title' => "Issue {$n}", ...($n === 12 ? ['status' => 'In Progress'] : [])]);
    }
    $this->issue = fn (int $n) => Issue::where('number', $n)->with('project', 'status')->sole();
    $this->links = app(LinkService::class);
    $this->links->link(($this->issue)(12), 'blocks', ($this->issue)(15), $this->admin);
});

test('web: moving a blocked issue to In Progress asks first, then moves', function () {
    $this->actingAs($this->admin);

    $this->patch(w('/issues/WEB-15'), ['version' => 1, 'status' => 'In Progress'])
        ->assertSessionHasErrors(['confirm' => "WEB-15 is blocked by WEB-12 (In Progress), which isn't resolved."]);
    expect(($this->issue)(15)->status->name)->toBe('To Do');
    $this->post(w('/issues/WEB-15/move'), ['status' => 'In Review'])->assertSessionHasErrors('confirm');
    expect(($this->issue)(15)->status->name)->toBe('To Do');

    $this->patch(w('/issues/WEB-15'), ['version' => 1, 'status' => 'In Progress', 'confirm' => true])->assertSessionHasNoErrors();
    expect(($this->issue)(15)->status->name)->toBe('In Progress');
    // Already started: moving within the category doesn't ask again; the board move does when entering it.
    $this->post(w('/issues/WEB-15/move'), ['status' => 'In Review'])->assertSessionHasNoErrors();
});

test('REST PATCH and move refuse without confirm and change nothing; with confirm they move', function () {
    apiAs($this->admin, AgentLevel::Write);

    $this->patchJson('/api/v1/issues/WEB-15', ['version' => 1, 'status' => 'In Progress', 'title' => 'Renamed'])->assertUnprocessable()
        ->assertJsonPath('errors.confirm.0', BLOCKED);
    $this->postJson('/api/v1/issues/WEB-15/move', ['status' => 'In Progress'])->assertUnprocessable()->assertJsonPath('errors.confirm.0', BLOCKED);
    expect(($this->issue)(15))->status->name->toBe('To Do')->title->toBe('Issue 15')->version->toBe(1);

    $this->postJson('/api/v1/issues/WEB-15/move', ['status' => 'In Progress', 'confirm' => true])->assertOk()->assertJsonPath('data.status', 'In Progress');
});

test('MCP move_issue and update_issue refuse without confirm; with confirm they move', function () {
    apiAs($this->admin, AgentLevel::Write);

    PmServer::tool(MoveIssue::class, ['issue' => 'WEB-15', 'status' => 'In Progress'])->assertHasErrors([BLOCKED]);
    PmServer::tool(UpdateIssue::class, ['issue' => 'WEB-15', 'version' => 1, 'status' => 'In Progress'])->assertHasErrors([BLOCKED]);
    expect(($this->issue)(15)->status->name)->toBe('To Do');

    PmServer::tool(UpdateIssue::class, ['issue' => 'WEB-15', 'version' => 1, 'status' => 'In Progress', 'confirm' => true])->assertOk();
    expect(($this->issue)(15)->status->name)->toBe('In Progress');
});

test('starting a successor whose predecessor is unresolved warns the same way', function () {
    $this->links->link(($this->issue)(12), 'precedes', ($this->issue)(14), $this->admin);
    apiAs($this->admin, AgentLevel::Write);

    $this->postJson('/api/v1/issues/WEB-14/move', ['status' => 'In Progress'])->assertUnprocessable()
        ->assertJsonPath('errors.confirm.0', "WEB-14 follows WEB-12 (In Progress), which isn't resolved. Send confirm: true to move it anyway.");
    PmServer::tool(MoveIssue::class, ['issue' => 'WEB-14', 'status' => 'In Progress', 'confirm' => true])->assertOk();
});

test('several blockers are named in one sentence; resolved ones are not', function () {
    $this->links->link(($this->issue)(11), 'blocks', ($this->issue)(15), $this->admin);
    $this->links->link(($this->issue)(10), 'blocks', ($this->issue)(15), $this->admin);
    apiAsPerson($this->admin);
    $this->postJson('/api/v1/issues/WEB-10/move', ['status' => 'Done'])->assertOk();
    apiAs($this->admin, AgentLevel::Write);

    $this->postJson('/api/v1/issues/WEB-15/move', ['status' => 'In Progress'])->assertUnprocessable()
        ->assertJsonPath('errors.confirm.0', "WEB-15 is blocked by WEB-11 (To Do) and WEB-12 (In Progress), which aren't resolved. Send confirm: true to move it anyway.");

    apiAsPerson($this->admin);
    $this->postJson('/api/v1/issues/WEB-11/move', ['status' => 'Done'])->assertOk();
    $this->postJson('/api/v1/issues/WEB-12/move', ['status' => 'Done'])->assertOk();
    $this->postJson('/api/v1/issues/WEB-15/move', ['status' => 'In Progress'])->assertOk();
});

test('bulk_update_issues with warned items changes nothing and lists every warned key', function () {
    $this->links->link(($this->issue)(12), 'blocks', ($this->issue)(13), $this->admin);
    apiAs($this->admin, AgentLevel::Write);
    $updates = [
        ['key' => 'WEB-15', 'version' => 1, 'status' => 'In Progress'],
        ['key' => 'WEB-14', 'version' => 1, 'title' => 'Renamed'],
        ['key' => 'WEB-13', 'version' => 1, 'status' => 'In Progress'],
    ];

    $errors = $this->patchJson('/api/v1/issues/bulk', ['updates' => $updates])->assertUnprocessable()->json('errors');
    expect(array_keys($errors))->toBe(['updates.0.confirm', 'updates.2.confirm'])
        ->and($errors['updates.0.confirm'][0])->toBe('[updates.0] '.BLOCKED)
        ->and($errors['updates.2.confirm'][0])->toStartWith('[updates.2] WEB-13 is blocked by WEB-12');
    PmServer::tool(BulkUpdateIssues::class, ['updates' => $updates])->assertHasErrors(['[updates.0] WEB-15 is blocked', '[updates.2] WEB-13 is blocked']);
    expect(($this->issue)(14)->title)->toBe('Issue 14')->and(($this->issue)(15)->status->name)->toBe('To Do');

    $updates[0]['confirm'] = $updates[2]['confirm'] = true;
    $this->patchJson('/api/v1/issues/bulk', ['updates' => $updates])->assertOk();
    expect(($this->issue)(13)->status->name)->toBe('In Progress');
});

test('a move into Done by an agent still opens an approval after confirm', function () {
    apiAs($this->admin, AgentLevel::Write);

    $this->postJson('/api/v1/issues/WEB-15/move', ['status' => 'Done', 'confirm' => true])->assertStatus(202)->assertJsonPath('status', 'pending_approval');
    expect(ApprovalRequest::count())->toBe(1)->and(($this->issue)(15)->status->name)->toBe('To Do');
});
