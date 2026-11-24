<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\IssueService;
use App\Services\NotificationService;

// 3-R6: changes by one actor to one issue within 5 minutes join the recipient's newest unread row.

beforeEach(function () {
    $this->ann = User::factory()->create();
    $this->project = makeProject($this->ann, 'WEB');
    $this->bob = addMember($this->project, User::factory()->create(), ProjectRole::Member);
    $this->cat = addMember($this->project, User::factory()->create(), ProjectRole::Member);
    $this->issue = makeIssue($this->project, $this->ann);
    $this->issues = app(IssueService::class);
});

function bump(): void
{
    $issue = test()->issue->refresh();
    test()->issues->update($issue, test()->bob, ['status' => $issue->status->name === 'In Progress' ? 'To Do' : 'In Progress']);
}

test('three edits by one actor within 5 minutes make one row with count 3, and a fourth after 6 minutes a new one', function () {
    bump();
    $this->travel(1)->minute();
    bump();
    $this->travel(1)->minute();
    bump();

    $row = InboxItem::where('user_id', $this->ann->id)->sole();
    expect($row->count)->toBe(3)->and($row->changes)->toHaveCount(3);

    $this->travel(6)->minutes();
    bump();
    expect(InboxItem::where('user_id', $this->ann->id)->count())->toBe(2);
});

test('a read row is not extended, and another actor gets a separate row', function () {
    bump();
    InboxItem::where('user_id', $this->ann->id)->update(['read_at' => now()]);
    bump();
    expect(InboxItem::where('user_id', $this->ann->id)->count())->toBe(2);

    $this->issues->update($this->issue->refresh(), $this->cat, ['status' => 'In Review']);
    expect(InboxItem::where('user_id', $this->ann->id)->count())->toBe(3);
});

test('approval requests and mentions never batch', function () {
    $notes = app(NotificationService::class);
    $notes->notify($this->ann, $this->issue, 'mention', ['action' => 'mention', 'from' => null, 'to' => null], $this->bob, null, 'web', batch: false);
    $notes->notify($this->ann, $this->issue, 'mention', ['action' => 'mention', 'from' => null, 'to' => null], $this->bob, null, 'web', batch: false);
    // A batchable change doesn't join a mention row either.
    $notes->notify($this->ann, $this->issue, 'commented', ['action' => 'commented', 'from' => null, 'to' => null], $this->bob, null, 'web');
    expect(InboxItem::where('user_id', $this->ann->id)->count())->toBe(3);

    [, $token] = makeAgent($this->bob, AgentLevel::Write, name: 'helper');
    asToken($token)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertStatus(202);
    $this->issues->update($this->issue->refresh(), $this->cat, ['status' => 'In Review']);
    expect(InboxItem::where('user_id', $this->bob->id)->where('event', 'approval_request')->sole()->count)->toBe(1);
});
