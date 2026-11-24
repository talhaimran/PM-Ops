<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\SearchIssues;
use App\Mcp\Tools\UnwatchIssue;
use App\Mcp\Tools\WatchIssue;
use App\Models\Issue;
use App\Models\User;
use App\Services\CommentService;
use App\Services\IssueService;
use App\Services\WatcherService;
use Inertia\Testing\AssertableInertia as Assert;

// Spec 7.10 (3-R2, 3-R3): watchers, auto-watching and who may add whom.

beforeEach(function () {
    $this->ann = User::factory()->create(['email' => 'ann@example.com']);
    $this->project = makeProject($this->ann, 'WEB');
    $this->bob = addMember($this->project, User::factory()->create(['email' => 'bob@example.com']), ProjectRole::Member);
    $this->cat = addMember($this->project, User::factory()->create(['email' => 'cat@example.com']), ProjectRole::Member);
    $this->vic = addMember($this->project, User::factory()->create(['email' => 'vic@example.com']), ProjectRole::Viewer);
    $this->issue = makeIssue($this->project, $this->ann, ['title' => 'First']);
    $this->watchers = app(WatcherService::class);
});

function watcherEmails(Issue $issue): array
{
    return app(WatcherService::class)->watchers($issue)->pluck('email')->sort()->values()->all();
}

test('the reporter, an assignee and a commenter start watching', function () {
    expect(watcherEmails($this->issue))->toBe(['ann@example.com']);

    app(IssueService::class)->update($this->issue, $this->ann, ['assignee' => 'bob@example.com']);
    app(CommentService::class)->add($this->issue, $this->cat, 'Hello');

    expect(watcherEmails($this->issue))->toBe(['ann@example.com', 'bob@example.com', 'cat@example.com']);
    // Assigned on create too.
    $second = makeIssue($this->project, $this->ann, ['assignee' => 'bob@example.com']);
    expect(watcherEmails($second))->toBe(['ann@example.com', 'bob@example.com']);
});

test('auto_watch off stops auto-watching but manual watching still works', function () {
    $this->bob->forceFill(['auto_watch' => false])->save();
    app(IssueService::class)->update($this->issue, $this->ann, ['assignee' => 'bob@example.com']);
    app(CommentService::class)->add($this->issue, $this->bob, 'Hi');
    expect(watcherEmails($this->issue))->toBe(['ann@example.com']);

    $this->watchers->watch($this->issue, $this->bob, $this->bob);
    expect(watcherEmails($this->issue))->toBe(['ann@example.com', 'bob@example.com']);
});

test('auto-watch ignores someone who cannot see the issue and keeps a manual watch manual', function () {
    $outsider = User::factory()->create();
    $this->watchers->autoWatch($this->issue, $outsider);
    expect(watcherEmails($this->issue))->toBe(['ann@example.com']);

    $this->watchers->watch($this->issue, $this->bob, $this->bob);
    $this->watchers->autoWatch($this->issue, $this->bob);
    expect(DB::table('issue_watchers')->where('user_id', $this->bob->id)->value('source'))->toBe('manual');
});

test('a member adds a teammate and anyone removes themselves', function () {
    $this->actingAs($this->bob)->postJson('/api/v1/issues/WEB-1/watchers', ['user' => 'cat@example.com'])->assertCreated()
        ->assertJsonPath('data.watchers_count', 2);
    expect(watcherEmails($this->issue))->toBe(['ann@example.com', 'cat@example.com']);

    $this->actingAs($this->cat)->deleteJson('/api/v1/issues/WEB-1/watchers')->assertNoContent();
    expect(watcherEmails($this->issue))->toBe(['ann@example.com']);
});

test('a viewer watches only themselves', function () {
    $this->actingAs($this->vic)->postJson('/api/v1/issues/WEB-1/watchers', ['user' => 'cat@example.com'])->assertForbidden();
    $this->actingAs($this->vic)->postJson('/api/v1/issues/WEB-1/watchers')->assertCreated();
    expect(watcherEmails($this->issue))->toBe(['ann@example.com', 'vic@example.com']);
});

test('removing another person needs project admin', function () {
    $this->watchers->watch($this->issue, $this->cat, $this->cat);
    $this->actingAs($this->bob)->deleteJson('/api/v1/issues/WEB-1/watchers', ['user' => 'cat@example.com'])->assertForbidden();
    $this->actingAs($this->ann)->deleteJson('/api/v1/issues/WEB-1/watchers', ['user' => 'cat@example.com'])->assertNoContent();
    expect(watcherEmails($this->issue))->toBe(['ann@example.com']);
});

test('someone who cannot see the issue is a 422 that lists only project members', function () {
    $other = User::factory()->create(['email' => 'zed@example.com']);
    $this->project->workspace->addMember($other, WorkspaceRole::Member);

    $this->actingAs($this->bob)->postJson('/api/v1/issues/WEB-1/watchers', ['user' => 'zed@example.com'])->assertUnprocessable()
        ->assertJsonPath('errors.user.0', 'zed@example.com is not a member of WEB. Add one of: ann@example.com, bob@example.com, cat@example.com, vic@example.com.');
});

test('the web routes watch and unwatch, and the issue page carries the count and list', function () {
    $this->actingAs($this->bob)->post(w('/issues/WEB-1/watchers'))->assertRedirect();
    $this->actingAs($this->bob)->get(w('/issues/WEB-1'))->assertInertia(fn (Assert $page) => $page
        ->where('issue.watching', true)->where('issue.watchers_count', 2)->has('issue.watchers', 2));

    $this->actingAs($this->bob)->delete(w('/issues/WEB-1/watchers'))->assertRedirect();
    expect(watcherEmails($this->issue))->toBe(['ann@example.com']);
});

test('a hidden issue is not found', function () {
    $stranger = User::factory()->create();
    joinWorkspace($stranger);
    $this->actingAs($stranger)->postJson('/api/v1/issues/WEB-1/watchers')->assertNotFound();
});

test('an agent watches as its owner, and a read-only agent cannot', function () {
    [, $write] = makeAgent($this->bob, AgentLevel::Write);
    [, $read] = makeAgent($this->bob, AgentLevel::Read);

    asToken($read)->postJson('/api/v1/issues/WEB-1/watchers')->assertForbidden();
    expect(mcpJson($write, 'watch_issue', ['issue' => 'WEB-1'])['watching'])->toBeTrue();
    expect(watcherEmails($this->issue))->toBe(['ann@example.com', 'bob@example.com']);

    asToken($write)->postJson('/api/v1/issues/WEB-1/watchers', ['user' => 'cat@example.com'])->assertForbidden();
    asToken($read)->deleteJson('/api/v1/issues/WEB-1/watchers')->assertForbidden();
    expect(mcpJson($write, 'unwatch_issue', ['issue' => 'WEB-1'])['watching'])->toBeFalse();
    expect(watcherEmails($this->issue))->toBe(['ann@example.com']);
});

test('watch_issue and unwatch_issue over mcp as a person', function () {
    apiAsPerson($this->bob);
    PmServer::tool(WatchIssue::class, ['issue' => 'WEB-1'])->assertOk()->assertSee('"watchers_count":2');
    PmServer::tool(UnwatchIssue::class, ['issue' => 'WEB-1'])->assertOk()->assertSee('"watchers_count":1');
});

test('the watching filter on the web list, REST and MCP', function () {
    $other = makeIssue($this->project, $this->bob, ['title' => 'Second']);
    $this->watchers->watch($this->issue, $this->bob, $this->bob);
    apiAsPerson($this->bob);

    $this->getJson('/api/v1/issues?watching=true')->assertOk()->assertJsonCount(2, 'data');
    $this->watchers->unwatch($this->issue, $this->bob, $this->bob);
    $this->getJson('/api/v1/issues?watching=true')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', $other->key);
    $this->getJson('/api/v1/issues?watching=false')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'WEB-1');
    $this->getJson('/api/v1/issues?watching=maybe')->assertUnprocessable();

    PmServer::tool(SearchIssues::class, ['watching' => true])->assertOk()->assertSee(['1 of 1', 'WEB-2'])->assertDontSee('WEB-1"');
    $this->actingAs($this->bob)->get(w('/projects/WEB/issues?watching=true'))->assertInertia(fn (Assert $page) => $page
        ->has('issues.data', 1)->where('issues.data.0.key', 'WEB-2'));
});

test('the dashboard Watching section lists visible open issues only', function () {
    $gone = makeIssue($this->project, $this->bob, ['title' => 'Gone']);
    app(IssueService::class)->delete($gone, $this->bob);
    $this->watchers->watch($this->issue, $this->bob, $this->bob);
    $done = makeIssue($this->project, $this->bob, ['title' => 'Finished']);
    app(IssueService::class)->update($done, $this->bob, ['status' => 'Done']);

    // A hidden project: bob is no longer a member, so his watch row is left out.
    $secret = makeProject($this->ann, 'SEC');
    $hidden = makeIssue($secret, $this->ann, ['title' => 'Hidden']);
    DB::table('issue_watchers')->insert(['issue_id' => $hidden->id, 'user_id' => $this->bob->id, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->bob)->get(w('/dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('watching', 1)->where('watching.0.key', 'WEB-1'));
});

test('auto_watch is a profile setting', function () {
    $this->actingAs($this->bob)->patch(route('profile.update'), ['auto_watch' => false])->assertSessionHasNoErrors();
    expect($this->bob->fresh()->auto_watch)->toBeFalse();
    $this->actingAs($this->bob)->patch(route('profile.update'), ['auto_watch' => 'maybe'])->assertSessionHasErrors('auto_watch');
});

test('a watcher who left the project disappears from the list and count; a non-member workspace admin who watches still shows', function () {
    $this->watchers->watch($this->issue, $this->cat, $this->cat);
    $admin = User::factory()->create(['email' => 'wsadmin@example.com']);
    $this->project->workspace->addMember($admin, WorkspaceRole::Admin);
    $this->watchers->watch($this->issue, $admin, $admin);
    expect(watcherEmails($this->issue))->toBe(['ann@example.com', 'cat@example.com', 'wsadmin@example.com']);

    $this->project->members()->detach($this->cat->id);

    expect(watcherEmails($this->issue))->toBe(['ann@example.com', 'wsadmin@example.com'])
        ->and($this->watchers->isWatching($this->issue, $this->cat))->toBeFalse()
        ->and($this->watchers->isWatching($this->issue, $admin))->toBeTrue();
    apiAsPerson($this->bob);
    $this->getJson('/api/v1/issues/WEB-1')->assertOk()->assertJsonPath('data.watchers_count', 2)->assertJsonCount(2, 'data.watchers')
        ->assertDontSee('cat@example.com');
    expect(mcpJson(makeAgent($this->bob, AgentLevel::Read)[1], 'get_issue', ['issue' => 'WEB-1'])['watchers_count'])->toBe(2);
});
