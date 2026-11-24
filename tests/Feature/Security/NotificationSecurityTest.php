<?php

use App\Enums\ProjectRole;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\CommentService;
use App\Services\IssueService;
use App\Services\NotificationService;
use App\Services\WatcherService;

// Step 3 Review Focus 1: the inbox never shows what the reader can no longer see.

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
