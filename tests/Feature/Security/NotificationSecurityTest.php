<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\CommentService;
use App\Services\IssueService;
use App\Services\NotificationService;
use App\Services\WatcherService;

// Step 3 Review Focus 1: the inbox never shows what the reader can no longer see.

/** What get_updates answers $user's agent (REST and MCP alike), optionally scoped to project keys. */
function updatesOf(User $user, array $projects = []): array
{
    [, $token] = makeAgent($user, AgentLevel::Write, $projects);
    $rest = asToken($token)->getJson('/api/v1/me/updates')->assertOk()->json('data');
    expect(collect(mcpJson($token, 'get_updates')['updates'])->pluck('id')->all())->toBe(collect($rest)->pluck('id')->all());

    return $rest;
}

beforeEach(function () {
    $this->ann = User::factory()->create();
    $this->project = makeProject($this->ann, 'WEB');
    $this->bob = addMember($this->project, User::factory()->create(), ProjectRole::Member);
    $this->issue = makeIssue($this->project, $this->ann);
    app(WatcherService::class)->watch($this->issue, $this->bob, $this->bob);
    $this->ws = $this->project->workspace;
    $this->notes = app(NotificationService::class);
});

test('a removed member gets nothing new and the inbox hides old items', function () {
    app(CommentService::class)->add($this->issue, $this->ann, 'Before');
    expect($this->notes->inbox($this->bob, $this->ws)->total())->toBe(1);

    $this->project->members()->detach($this->bob->id);
    app(CommentService::class)->add($this->issue, $this->ann, 'After');

    expect(InboxItem::where('user_id', $this->bob->id)->count())->toBe(1)
        ->and($this->notes->inbox($this->bob, $this->ws)->total())->toBe(0)
        ->and($this->notes->unreadCount($this->bob, $this->ws))->toBe(0);
});

test('a deleted issue takes its notifications out of the inbox, count and get_updates', function () {
    app(CommentService::class)->add($this->issue, $this->ann, 'Hello');
    app(IssueService::class)->delete($this->issue, $this->ann);

    expect($this->notes->inbox($this->bob, $this->ws)->total())->toBe(0)->and($this->notes->unreadCount($this->bob, $this->ws))->toBe(0);
    $this->travel(2)->seconds();
    expect(updatesOf($this->bob))->toBe([]);
});

test('get_updates leaves out a project the token is scoped away from', function () {
    $api = makeProject($this->ann, 'API');
    addMember($api, $this->bob, ProjectRole::Member);
    $other = makeIssue($api, $this->ann);
    app(WatcherService::class)->watch($other, $this->bob, $this->bob);
    app(CommentService::class)->add($other, $this->ann, 'Hi');
    app(CommentService::class)->add($this->issue, $this->ann, 'Hello');
    $this->travel(2)->seconds();

    expect(collect(updatesOf($this->bob))->pluck('issue')->sort()->values()->all())->toBe(['API-1', 'WEB-1'])
        ->and(collect(updatesOf($this->bob, ['WEB']))->pluck('issue')->all())->toBe(['WEB-1']);
});

test("another workspace's notifications never show, and another user's cannot be marked read", function () {
    app(CommentService::class)->add($this->issue, $this->ann, 'Hello');
    $mine = InboxItem::where('user_id', $this->bob->id)->sole();

    $this->actingAs($this->ann)->postJson(w("/notifications/{$mine->id}/read"))->assertNotFound();
    expect($mine->refresh()->read_at)->toBeNull();

    $other = makeWorkspace($this->bob, 'other');
    expect($this->notes->inbox($this->bob, $other)->total())->toBe(0)->and($this->notes->unreadCount($this->bob, $other))->toBe(0);
    $this->actingAs($this->bob)->get('/other/notifications')->assertOk()->assertInertia(fn ($p) => $p->has('items.data', 0));
    $this->actingAs($this->bob)->postJson("/other/notifications/{$mine->id}/read")->assertNotFound();
});

/** bob's agent touches WEB-1 and OPS-1 yesterday; the daily summary is then made. */
function summaryOfTwoProjects(): array
{
    $ops = makeProject(test()->ann, 'OPS');
    addMember($ops, test()->bob, ProjectRole::Member);
    makeIssue($ops, test()->ann);
    [, $token] = makeAgent(test()->bob, AgentLevel::Write, name: 'helper');
    foreach (['WEB-1', 'OPS-1'] as $key) {
        asToken($token)->postJson("/api/v1/issues/{$key}/move", ['status' => 'In Progress'])->assertOk();
    }
    test()->travel(1)->day();
    test()->artisan('notifications:agent-summary')->assertSuccessful();

    return [$ops, $token];
}

test('a summary names only the issues its reader can still see, with the count of those', function () {
    [$ops, $token] = summaryOfTwoProjects();
    expect(InboxItem::where('event', 'agent_summary')->where('user_id', $this->bob->id)->sole()->count)->toBe(2);
    $ops->members()->detach($this->bob->id);

    $page = $this->actingAs($this->bob)->get(w('/notifications'));
    $page->assertInertia(fn ($p) => $p->has('items.data', 1)->where('items.data.0.keys', ['WEB-1'])
        ->where('items.data.0.text', fn ($t) => str_contains($t, 'updated 1 issue ')));
    expect($page->getContent())->not->toContain('OPS-1');
    expect(json_encode(asToken($token)->getJson('/api/v1/me/updates')->json()))->not->toContain('OPS-1')
        ->and(json_encode(mcpJson($token, 'get_updates')))->not->toContain('OPS-1');
});

test('a summary with no issue left visible is gone from the inbox and the unread count', function () {
    [$ops] = summaryOfTwoProjects();
    expect($this->notes->unreadCount($this->bob, $this->ws))->toBe(1);

    $ops->members()->detach($this->bob->id);
    $this->project->members()->detach($this->bob->id);

    expect($this->notes->inbox($this->bob, $this->ws)->total())->toBe(0)->and($this->notes->unreadCount($this->bob, $this->ws))->toBe(0);
});
