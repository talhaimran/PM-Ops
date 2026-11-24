<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Http\Resources\InboxItemResource;
use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\CommentService;
use App\Services\IssueService;
use App\Services\NotificationService;
use App\Services\StatusService;
use App\Services\WatcherService;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

// Spec 7.10 (3-R4, 3-R5, 3-R8, 3-R25): who is told about what, the inbox, and get_updates.

beforeEach(function () {
    $this->ann = User::factory()->create(['name' => 'Ann Admin', 'email' => 'ann@example.com']);
    $this->project = makeProject($this->ann, 'WEB');
    $this->bob = addMember($this->project, User::factory()->create(['name' => 'Bob Builder', 'email' => 'bob@example.com']), ProjectRole::Member);
    $this->cat = addMember($this->project, User::factory()->create(['name' => 'Cat Coder', 'email' => 'cat@example.com']), ProjectRole::Member);
    $this->issue = makeIssue($this->project, $this->ann, ['title' => 'First']);
    foreach ([$this->bob, $this->cat] as $u) {
        app(WatcherService::class)->watch($this->issue, $u, $u);
    }
    $this->notes = app(NotificationService::class);
});

function inboxOf(User $user): Collection
{
    return InboxItem::where('user_id', $user->id)->orderBy('id')->get();
}

test('a comment notifies the other watchers and not the commenter', function () {
    app(CommentService::class)->add($this->issue, $this->bob, 'Hello');

    expect(inboxOf($this->ann))->toHaveCount(1)->and(inboxOf($this->ann)[0]->event)->toBe('commented')
        ->and(inboxOf($this->cat))->toHaveCount(1)
        ->and(inboxOf($this->bob))->toHaveCount(0);
});

test('status, assignee, resolve and reopen each notify the other watchers', function () {
    $issues = app(IssueService::class);
    $issues->update($this->issue, $this->bob, ['status' => 'In Progress']);
    $issues->update($this->issue->refresh(), $this->bob, ['assignee' => 'cat@example.com']);
    $issues->update($this->issue->refresh(), $this->bob, ['status' => 'Done']);
    $issues->update($this->issue->refresh(), $this->bob, ['status' => 'To Do']);

    // Separate changes, so read between to see each event on its own row.
    expect(inboxOf($this->ann)->pluck('changes')->flatten(1)->pluck('action')->all())->toBe(['status_changed', 'assignee_changed', 'resolved', 'reopened'])
        ->and(inboxOf($this->bob))->toHaveCount(0);
});

test('label, priority and title edits do not notify, and a mixed change notifies for its notifying part only', function () {
    $issues = app(IssueService::class);
    $issues->update($this->issue, $this->bob, ['priority' => 'high', 'title' => 'Renamed']);
    expect(inboxOf($this->ann))->toHaveCount(0);

    $issues->update($this->issue->refresh(), $this->bob, ['title' => 'Again', 'status' => 'In Progress']);
    $row = inboxOf($this->ann)->sole();
    expect($row->changes)->toHaveCount(1)->and($row->changes[0]['action'])->toBe('status_changed')
        ->and($row->changes[0]['to'])->toBe('In Progress');
});

test('an agent moving an issue notifies the other watchers by its label and not its owner', function () {
    [$agent, $token] = makeAgent($this->bob, AgentLevel::Write, name: 'helper');
    $this->bob->forceFill(['name' => 'Alex Smith'])->save();

    asToken($token)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'In Progress'])->assertOk();

    $row = inboxOf($this->ann)->sole();
    expect(InboxItemResource::make($row->load(['issue.project', 'agent.owner', 'actor']))->resolve()['text'])->toBe("Alex's agent moved WEB-1 to In Progress")
        ->and($row->agent_id)->toBe($agent->id)
        ->and(inboxOf($this->bob))->toHaveCount(0);
});

test('an agent asking for Done notifies its owner at once, never an Admin, and a rolled-back request nobody', function () {
    [, $token] = makeAgent($this->bob, AgentLevel::Write, name: 'helper');

    asToken($token)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertStatus(202);

    $row = inboxOf($this->bob)->sole();
    expect($row->event)->toBe('approval_request')->and($row->read_at)->toBeNull()
        ->and(inboxOf($this->ann)->where('event', 'approval_request'))->toHaveCount(0);

    // Exactly once: asking again returns the open request and tells nobody.
    asToken($token)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertStatus(202);
    expect(inboxOf($this->bob)->where('event', 'approval_request'))->toHaveCount(1);
});

test('a first-time request notifies only once its transaction commits, and a rolled-back one nobody', function () {
    [$agent] = makeAgent($this->bob, AgentLevel::Write, name: 'helper');
    $fresh = makeIssue($this->project, $this->ann, ['title' => 'Fresh']);
    $status = app(StatusService::class)->resolveFor($this->project, 'Done');
    $ask = function () use ($fresh, $agent, $status) {
        app(ApprovalService::class)->requestDone($fresh, $this->bob, $agent, $status, null);
        // Still inside the transaction: nothing may be sent yet.
        expect(inboxOf($this->bob)->where('event', 'approval_request'))->toHaveCount(0);
    };

    try {
        DB::transaction(function () use ($ask) {
            $ask();
            throw new LogicException('boom');
        });
    } catch (LogicException $e) {
        // Only our own rollback: a failed expectation inside $ask (a PHPUnit exception) must fail the test.
        expect($e->getMessage())->toBe('boom');
    }
    expect(ApprovalRequest::count())->toBe(0)->and(inboxOf($this->bob)->where('event', 'approval_request'))->toHaveCount(0);

    DB::transaction($ask);
    expect(inboxOf($this->bob)->where('event', 'approval_request'))->toHaveCount(1);
});

test('get_updates re-sends a row that later changes batched into, with its new count', function () {
    [, $token] = makeAgent($this->ann, AgentLevel::Write, name: 'mine');
    app(IssueService::class)->update($this->issue, $this->bob, ['status' => 'In Progress']);
    $this->travel(2)->seconds();
    $first = asToken($token)->getJson('/api/v1/me/updates')->assertOk()->json();
    expect($first['data'])->toHaveCount(1)->and($first['data'][0]['count'])->toBe(1);

    app(IssueService::class)->update($this->issue->refresh(), $this->bob, ['status' => 'In Review']);
    $this->travel(2)->seconds();
    $second = asToken($token)->getJson('/api/v1/me/updates?cursor='.$first['meta']['next_cursor'])->assertOk()->json('data');

    expect($second)->toHaveCount(1)->and($second[0]['count'])->toBe(2)->and($second[0]['text'])->toBe('Bob Builder made 2 changes to WEB-1');
    // Reading it (web) doesn't re-stream it.
    $this->actingAs($this->ann)->post(w('/notifications/read-all'));
    $cursor = asToken($token)->getJson('/api/v1/me/updates?cursor='.$first['meta']['next_cursor'])->json('meta.next_cursor');
    $this->travel(2)->seconds();
    expect(asToken($token)->getJson('/api/v1/me/updates?cursor='.$cursor)->json('data'))->toBe([]);
});

test('since honours its own UTC offset', function () {
    [, $token] = makeAgent($this->ann, AgentLevel::Write, name: 'mine');
    app(IssueService::class)->update($this->issue, $this->bob, ['status' => 'In Progress']);
    $this->travel(2)->seconds();
    $at = fn ($minutes) => urlencode(now()->addMinutes($minutes)->setTimezone('+05:00')->format('Y-m-d\TH:i:sP'));

    // -1 minute is the discriminating case (read as local time it would be 5 hours ahead); +1 minute is empty either way.
    expect(asToken($token)->getJson('/api/v1/me/updates?since='.$at(-1))->json('data'))->toHaveCount(1)
        ->and(asToken($token)->getJson('/api/v1/me/updates?since='.$at(1))->json('data'))->toBe([]);
});

test('mark read, mark all read and the unread count', function () {
    app(CommentService::class)->add($this->issue, $this->bob, 'One');
    $this->travel(10)->minutes();
    app(CommentService::class)->add($this->issue, $this->bob, 'Two');
    $ws = $this->project->workspace;
    expect($this->notes->unreadCount($this->ann, $ws))->toBe(2);

    $first = inboxOf($this->ann)->first();
    $this->actingAs($this->ann)->post(w("/notifications/{$first->id}/read"))->assertRedirect();
    expect($this->notes->unreadCount($this->ann, $ws))->toBe(1);

    $this->actingAs($this->ann)->post(w('/notifications/read-all'))->assertRedirect();
    expect($this->notes->unreadCount($this->ann, $ws))->toBe(0)
        ->and($this->notes->unreadCount($this->cat, $ws))->toBe(2);
});

test('the inbox page lists items and shares the unread count', function () {
    app(CommentService::class)->add($this->issue, $this->bob, 'One');

    $this->actingAs($this->ann)->get(w('/notifications'))->assertInertia(fn (Assert $page) => $page
        ->component('Notifications/Index')
        ->where('unread_notifications', 1)
        ->has('items.data', 1)
        ->where('items.data.0.text', 'Bob Builder commented on WEB-1')
        ->where('items.data.0.issue', 'WEB-1'));
});

test('get_updates returns watched changes and approval outcomes in one stream, oldest first, after a cursor and since', function () {
    [$agent, $token] = makeAgent($this->ann, AgentLevel::Write, name: 'mine');
    $start = now();
    asToken($token)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertStatus(202);
    $this->travel(1)->minute();
    app(IssueService::class)->update($this->issue->refresh(), $this->bob, ['status' => 'In Progress']);
    $this->travel(1)->minute();
    app(ApprovalService::class)->reject(ApprovalRequest::sole(), $this->ann, 'later');
    // Past the 5-minute batch window, so the comment is its own row.
    $this->travel(6)->minutes();
    app(CommentService::class)->add($this->issue, $this->bob, 'Hi');
    $this->travel(2)->seconds();

    $all = asToken($token)->getJson('/api/v1/me/updates')->assertOk()->json();
    expect(collect($all['data'])->pluck('kind')->all())->toBe(['notification.status_changed', 'approval.rejected', 'notification.commented'])
        ->and($all['data'][0]['issue'])->toBe('WEB-1')->and($all['data'][0]['text'])->toBe('Bob Builder moved WEB-1 to In Progress');

    $page = asToken($token)->getJson('/api/v1/me/updates?per_page=2')->json();
    expect($page['data'])->toHaveCount(2);
    $rest = asToken($token)->getJson('/api/v1/me/updates?cursor='.$page['meta']['next_cursor'])->json();
    expect(collect($rest['data'])->pluck('kind')->all())->toBe(['notification.commented']);

    $since = urlencode($start->copy()->addSeconds(90)->toIso8601String());
    expect(collect(asToken($token)->getJson("/api/v1/me/updates?since={$since}")->json('data'))->pluck('kind')->all())->toBe(['approval.rejected', 'notification.commented']);
    // Reading doesn't mark anything read: the two changes and the agent's own request.
    expect($this->notes->unreadCount($this->ann, $this->project->workspace))->toBe(3);
});
