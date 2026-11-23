<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\BulkUpdateIssues;
use App\Mcp\Tools\GetIssue;
use App\Mcp\Tools\MoveIssue;
use App\Mcp\Tools\UpdateIssue;
use App\Models\Activity;
use App\Models\ApprovalRequest;
use App\Models\Issue;
use App\Models\User;
use App\Services\BoardService;
use App\Services\IssueService;

/*
 * Spec 7.11 (step 1, Task 4): issues carry a version; every edit sends the version it read and a mismatch stops the
 * save with what changed since. Moves just apply (1-D3); field, label, sprint, status and resolution changes bump it,
 * a rank-only reorder doesn't (1-D4).
 */

const VERSION_MESSAGE = 'Send the version you read (the `version` field of get_issue).';

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Ada Admin', 'email' => 'admin@example.com']);
    $this->sam = User::factory()->create(['name' => 'Sam Member', 'email' => 'sam@example.com']);
    $this->project = makeProject($this->admin, 'WEB');
    addMember($this->project, $this->sam, ProjectRole::Member);
    $this->issue = makeIssue($this->project, $this->admin, ['title' => 'Original']);
});

function web1(): Issue
{
    return Issue::where('number', 1)->sole();
}

test('an update with the version you read saves and returns the next version', function () {
    [, $token] = makeAgent($this->admin);

    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['title' => 'New', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);

    expect(web1())->title->toBe('New')->version->toBe(2)
        ->and(Activity::latest('id')->first()->issue_version)->toBe(2);
});

test('a stale version changes nothing and returns 409 with one change per field since, naming the agent', function () {
    [, $samToken] = makeAgent($this->sam);
    [, $adminToken] = makeAgent($this->admin);
    asToken($samToken)->patchJson('/api/v1/issues/WEB-1', ['title' => 'By the agent', 'priority' => 'high', 'version' => 1])->assertOk();
    app(IssueService::class)->update(web1(), $this->sam, ['priority' => 'low', 'version' => 2]);

    $response = asToken($adminToken)->patchJson('/api/v1/issues/WEB-1', ['description' => 'Mine', 'version' => 1])
        ->assertStatus(409)
        ->assertJsonPath('error', 'conflict')
        ->assertJsonPath('message', 'WEB-1 changed since version 1. Read it again and resend.')
        ->assertJsonPath('current_version', 3)
        ->assertJsonPath('issue.version', 3)
        ->assertJsonPath('issue.title', 'By the agent');

    $changes = collect($response->json('changes'))->keyBy('field');
    expect($changes->keys()->sort()->values()->all())->toBe(['priority', 'title'])
        ->and($changes['title'])->toMatchArray(['from' => 'Original', 'to' => 'By the agent', 'by' => 'Sam Member', 'agent' => "Sam's agent", 'via' => 'api'])
        // One entry per field: the first value it had since, the last one it got, and who made that last change.
        ->and($changes['priority'])->toMatchArray(['from' => 'medium', 'to' => 'low', 'by' => 'Sam Member', 'agent' => null, 'via' => 'web'])
        ->and($changes['priority']['at'])->toBeString()
        ->and(web1())->description->toBeNull()->version->toBe(3);
});

test('a missing version is a 422 on REST, MCP and web, and on each bulk item', function () {
    [, $token] = makeAgent($this->admin);
    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['title' => 'x'])->assertUnprocessable()->assertJsonPath('errors.version.0', VERSION_MESSAGE);
    asToken($token)->patchJson('/api/v1/issues/bulk', ['updates' => [['key' => 'WEB-1', 'title' => 'x']]])
        ->assertUnprocessable()->assertJsonPath('errors', ['updates.0.version' => [VERSION_MESSAGE]]);

    apiAs($this->admin);
    PmServer::tool(UpdateIssue::class, ['issue' => 'WEB-1', 'title' => 'x'])->assertHasErrors([VERSION_MESSAGE]);
    PmServer::tool(BulkUpdateIssues::class, ['updates' => [['key' => 'WEB-1', 'title' => 'x']]])->assertHasErrors([VERSION_MESSAGE]);

    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($this->admin)->patch(w('/issues/WEB-1'), ['title' => 'x'])->assertSessionHasErrors(['version' => VERSION_MESSAGE]);

    expect(web1())->title->toBe('Original')->version->toBe(1);
});

test('moves need no version; a status change bumps it, a rank-only reorder does not (1-D4)', function () {
    makeIssue($this->project, $this->admin);
    [, $token] = makeAgent($this->admin);

    asToken($token)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'In Progress'])->assertOk();
    expect(web1()->version)->toBe(2);

    asToken($token)->postJson('/api/v1/issues/WEB-1/move', ['position' => 0])->assertOk();
    asToken($token)->postJson('/api/v1/issues/WEB-2/rank', ['after' => 'WEB-1'])->assertOk();
    asToken($token)->postJson('/api/v1/issues/WEB-1/rank', ['position' => 0])->assertOk();
    expect(web1()->version)->toBe(2)->and(Issue::where('number', 2)->sole()->version)->toBe(1);

    apiAs($this->admin);
    PmServer::tool(MoveIssue::class, ['issue' => 'WEB-1', 'status' => 'To Do'])->assertOk()->assertSee('"version":3');
    expect(web1()->version)->toBe(3);
});

test('a no-op update does not bump the version', function () {
    [, $token] = makeAgent($this->admin);

    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['title' => 'Original', 'version' => 1])->assertOk()->assertJsonPath('data.version', 1);

    expect(web1()->version)->toBe(1);
});

test('a bulk update with stale items changes nothing and names every conflicting key (1-R11)', function () {
    makeIssue($this->project, $this->admin);
    makeIssue($this->project, $this->admin);
    app(IssueService::class)->update(Issue::where('number', 2)->sole(), $this->sam, ['title' => 'Sam was here']);
    app(IssueService::class)->update(Issue::where('number', 3)->sole(), $this->sam, ['priority' => 'high']);
    [, $token] = makeAgent($this->admin);

    asToken($token)->patchJson('/api/v1/issues/bulk', ['updates' => [
        ['key' => 'WEB-1', 'title' => 'A', 'version' => 1],
        ['key' => 'WEB-2', 'title' => 'B', 'version' => 1],
        ['key' => 'WEB-3', 'title' => 'C', 'version' => 1],
    ]])
        ->assertStatus(409)
        ->assertJsonPath('error', 'conflict')
        ->assertJsonPath('message', 'WEB-2, WEB-3 changed since the version you sent. Read them again and resend.')
        ->assertJsonPath('conflicts.0.key', 'WEB-2')
        ->assertJsonPath('conflicts.0.current_version', 2)
        ->assertJsonPath('conflicts.0.changes.0.field', 'title')
        ->assertJsonPath('conflicts.1.key', 'WEB-3');

    expect(Issue::orderBy('number')->pluck('title')->all())->toBe(['Original', 'Sam was here', 'Issue']);
});

test('fields=title still returns version, on REST and MCP', function () {
    [, $token] = makeAgent($this->admin);

    asToken($token)->getJson('/api/v1/issues/WEB-1?fields=title')->assertOk()->assertExactJson(['data' => ['key' => 'WEB-1', 'title' => 'Original', 'version' => 1]]);

    apiAs($this->admin);
    PmServer::tool(GetIssue::class, ['issue' => 'WEB-1', 'fields' => 'title'])->assertOk()->assertSee('{"key":"WEB-1","version":1,"title":"Original"}');
});

test('Undo stops with what changed after someone else edits the issue', function () {
    $this->actingAs($this->admin)->patch(w('/issues/WEB-1'), ['title' => 'Mine', 'version' => 1])->assertSessionHasNoErrors();
    $undo = session('undo');

    app(IssueService::class)->update(web1(), $this->sam, ['priority' => 'high']);

    $this->patch($undo['url'], $undo['data'])->assertSessionHasErrors(['undo' => "Can't undo: Sam Member changed priority on WEB-1."]);
    expect(web1())->title->toBe('Mine')->priority->value->toBe('high');
});

test('Undo of a move stops after an agent changed the issue, naming the agent', function () {
    [, $samToken] = makeAgent($this->sam);
    $this->actingAs($this->admin)->post(w('/issues/WEB-1/move'), ['status' => 'In Progress'])->assertSessionHasNoErrors();
    $undo = session('undo');

    asToken($samToken)->patchJson('/api/v1/issues/WEB-1', ['title' => 'Agent', 'version' => 2])->assertOk();
    $this->flushHeaders();
    app('auth')->forgetGuards();

    $this->actingAs($this->admin)->post($undo['url'], $undo['data'])->assertSessionHasErrors(['undo' => "Can't undo: Sam's agent changed title on WEB-1."]);
    expect(web1()->status->name)->toBe('In Progress');
});

test('Undo still works after someone else only reordered the issue', function () {
    makeIssue($this->project, $this->admin);
    $this->actingAs($this->admin)->post(w('/issues/WEB-1/move'), ['status' => 'In Progress'])->assertSessionHasNoErrors();
    $undo = session('undo');

    app(BoardService::class)->move(web1(), $this->sam, ['position' => 0]);

    $this->post($undo['url'], $undo['data'])->assertSessionHasNoErrors();
    expect(web1())->status->name->toBe('To Do')->version->toBe(3);
});

test('approving a request whose issue changed since is a 409 listing what changed; confirm_changed applies it', function () {
    [, $token] = makeAgent($this->admin, AgentLevel::Write);
    // The agent's other fields apply at once (A-L7); the request remembers the version it left the issue at.
    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['title' => 'Ready', 'status' => 'Done', 'version' => 1])->assertStatus(202);
    $approval = ApprovalRequest::sole();
    expect($approval->action['issue_version'])->toBe(2);

    app(IssueService::class)->update(web1(), $this->sam, ['priority' => 'high']);
    $this->flushHeaders();
    app('auth')->forgetGuards();

    $this->actingAs($this->admin)->post(w("/approvals/{$approval->id}/approve"))
        ->assertSessionHasErrors(['approval' => 'Sam Member changed priority on WEB-1 since this request. Approve again to move it to Done.']);
    expect(web1()->status->name)->toBe('To Do');

    $this->post(w("/approvals/{$approval->id}/approve"), ['confirm_changed' => true])->assertSessionHasNoErrors();
    expect(web1()->status->name)->toBe('Done');
});

test('an agent\'s own field change on the held move does not count as a change since its request', function () {
    [, $token] = makeAgent($this->admin, AgentLevel::Write);
    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['title' => 'Ready', 'status' => 'Done', 'version' => 1])->assertStatus(202);
    $this->flushHeaders();
    app('auth')->forgetGuards();

    $this->actingAs($this->admin)->post(w('/approvals/'.ApprovalRequest::sole()->id.'/approve'))->assertSessionHasNoErrors();
    expect(web1()->status->name)->toBe('Done');
});

test('web: a stale save flashes the conflict and keeps the input; overwrite with the current version saves and logs it', function () {
    app(IssueService::class)->update(web1(), $this->sam, ['description' => 'Theirs']);

    $this->actingAs($this->admin)->patch(w('/issues/WEB-1'), ['description' => 'Mine', 'version' => 1])
        ->assertSessionHasErrors(['version' => 'WEB-1 changed since version 1. Read it again and resend.'])
        ->assertSessionHasInput('description', 'Mine');
    expect(session('conflict'))->toMatchArray([
        'key' => 'WEB-1',
        'version' => 2,
        'yours' => ['description' => 'Mine'],
        'theirs' => ['description' => 'Theirs'],
        'by' => 'Sam Member',
    ])->and(session('conflict.at'))->toBeString()->and(web1()->description)->toBe('Theirs');

    // Overwriting with a version that is stale again conflicts again.
    $this->patch(w('/issues/WEB-1'), ['description' => 'Mine', 'version' => 1, 'overwrite' => true])->assertSessionHasErrors('version');
    expect(web1()->description)->toBe('Theirs');

    $this->patch(w('/issues/WEB-1'), ['description' => 'Mine', 'version' => 2, 'overwrite' => true])->assertSessionHasNoErrors();
    expect(web1())->description->toBe('Mine')->version->toBe(3)
        ->and(Activity::latest('id')->first())->action->toBe('overwrote')->changes->toEqual(['description' => ['from' => 'Theirs', 'to' => 'Mine']]);
});

test('M1: a description edit past character 200 is a change: it bumps, is logged, and a stale editor gets the conflict', function () {
    $long = str_repeat('a', 250);
    [, $token] = makeAgent($this->admin);
    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['description' => $long, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);

    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['description' => $long.'b', 'version' => 2])->assertOk()->assertJsonPath('data.version', 3);
    expect(Activity::where('action', 'updated')->count())->toBe(2);

    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['description' => 'Stale', 'version' => 2])->assertStatus(409);
    expect(web1()->description)->toBe($long.'b');
});

test('the conflict names the author of the latest change, not of the last field first changed', function () {
    app(IssueService::class)->update(web1(), $this->admin, ['title' => 'Ada 1']);
    $this->travel(1)->minutes();
    app(IssueService::class)->update(web1(), $this->sam, ['priority' => 'high']);
    $this->travel(1)->minutes();
    app(IssueService::class)->update(web1(), $this->admin, ['title' => 'Ada 2']);

    $this->actingAs($this->admin)->patch(w('/issues/WEB-1'), ['description' => 'Mine', 'version' => 1])->assertSessionHasErrors('version');
    expect(session('conflict.by'))->toBe('Ada Admin');
});

test('web: Save mine over theirs resends duplicate_of with a Duplicate resolution', function () {
    makeIssue($this->project, $this->admin);
    app(IssueService::class)->update(web1(), $this->sam, ['priority' => 'high']);

    $this->actingAs($this->admin)->patch(w('/issues/WEB-1'), ['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-2', 'version' => 1])
        ->assertSessionHasErrors('version');
    $conflict = session('conflict');
    expect($conflict['yours'])->toMatchArray(['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-2']);

    $this->patch($conflict['url'], [...$conflict['yours'], 'version' => $conflict['version'], 'overwrite' => true])->assertSessionHasNoErrors();
    expect(web1())->resolution->value->toBe('duplicate')->status->name->toBe('Done');
});

test('Can\'t undo names fields the way the activity log does', function () {
    $this->actingAs($this->admin)->patch(w('/issues/WEB-1'), ['title' => 'Mine', 'version' => 1])->assertSessionHasNoErrors();
    $undo = session('undo');

    app(IssueService::class)->update(web1(), $this->sam, ['story_points' => 5]);

    $this->patch($undo['url'], $undo['data'])->assertSessionHasErrors(['undo' => "Can't undo: Sam Member changed story points on WEB-1."]);
});

test('undo_of 0 is refused as input, not treated as an edit or an undo', function () {
    $this->actingAs($this->admin)->patch(w('/issues/WEB-1'), ['title' => 'x', 'version' => 1, 'undo_of' => 0])->assertSessionHasErrors('undo_of');
    $this->post(w('/issues/WEB-1/move'), ['status' => 'Done', 'undo_of' => 0])->assertSessionHasErrors('undo_of');
    expect(web1())->title->toBe('Original')->status->name->toBe('To Do');
});
