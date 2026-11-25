<?php

use App\Enums\AgentLevel;
use App\Models\InboxItem;
use App\Models\Issue;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

// 3-R7: one summary per owner for the previous day, visible keys only, nothing when zero.

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Alex Owner']);
    $this->project = makeProject($this->owner, 'WEB');
    $this->ws = $this->project->workspace;
    foreach ([1, 2, 3] as $n) {
        makeIssue($this->project, $this->owner, ['title' => "Issue {$n}"]);
    }
    [$this->agent, $this->token] = makeAgent($this->owner, AgentLevel::Write, name: 'helper');
});

function agentWorks(): void
{
    foreach ([1, 2] as $n) {
        asToken(test()->token)->postJson("/api/v1/issues/WEB-{$n}/move", ['status' => 'In Progress'])->assertOk();
    }
}

test('the owner gets one summary for yesterday with the issues the agent touched', function () {
    agentWorks();
    $this->travel(1)->day();

    $this->artisan('notifications:agent-summary')->assertSuccessful();
    $this->artisan('notifications:agent-summary')->assertSuccessful();

    $row = InboxItem::where('user_id', $this->owner->id)->where('event', 'agent_summary')->sole();
    expect($row->count)->toBe(2)->and($row->issue_id)->toBeNull()
        ->and(collect($row->changes)->pluck('issue_id')->filter()->values()->all())->toBe(Issue::whereIn('number', [1, 2])->orderBy('id')->pluck('id')->all())
        ->and(app(NotificationService::class)->inbox($this->owner, $this->ws)->first()->id)->toBe($row->id);
});

test('nothing is sent when the agent did nothing, and keys the owner cannot see are left out', function () {
    $this->travel(1)->day();
    $this->artisan('notifications:agent-summary')->assertSuccessful();
    expect(InboxItem::count())->toBe(0);

    $this->travel(-1)->day();
    agentWorks();
    $this->travel(1)->day();
    // The owner leaves WEB: none of its keys may be named.
    $this->project->members()->detach($this->owner->id);
    $this->ws->members()->updateExistingPivot($this->owner->id, ['role' => 'member']);
    $this->artisan('notifications:agent-summary')->assertSuccessful();
    expect(InboxItem::where('event', 'agent_summary')->count())->toBe(0);
});

test('a summary run waits for another one making the same owner and day, so two schedulers never make two rows', function () {
    agentWorks();
    // Today, without travel(): frozen time would keep the lock's wait from ever timing out.
    $day = now();
    // Another scheduler is between its check and its insert for this owner and day.
    $other = Cache::lock("pm:agent-summary:{$this->ws->id}:{$this->owner->id}:{$day->toDateString()}", 30);
    expect($other->get())->toBeTrue();

    expect(fn () => app(NotificationService::class)->summary($this->ws, $this->owner, $day))->toThrow(LockTimeoutException::class);
    expect(InboxItem::where('event', 'agent_summary')->count())->toBe(0);

    $other->release();
    app(NotificationService::class)->summary($this->ws, $this->owner, $day);
    app(NotificationService::class)->summary($this->ws, $this->owner, $day);
    expect(InboxItem::where('event', 'agent_summary')->count())->toBe(1);
});

test('the daily summary is scheduled on one server and never overlaps itself', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'notifications:agent-summary'));
    expect($event->onOneServer)->toBeTrue()->and($event->withoutOverlapping)->toBeTrue();
});

test('the command skips an owner another run is already summarising, and still finishes', function () {
    agentWorks();
    $day = now();
    $other = Cache::lock("pm:agent-summary:{$this->ws->id}:{$this->owner->id}:{$day->toDateString()}", 30);
    $other->get();

    $this->artisan('notifications:agent-summary', ['day' => $day->toDateString()])->assertSuccessful();
    expect(InboxItem::where('event', 'agent_summary')->count())->toBe(0);
    $other->release();
});
