<?php

use App\Services\SystemEventService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 3-R16, owner ruling on the PR 5 review: every job below also runs in suspended workspaces. Suspension stops people,
// agents and staff from changing things; this is upkeep they already chose or the system owes (trash retention, the
// Owner's scheduled deletion, approval expiry, run-log pruning, link repair, support-session end, yesterday's agent summary).

// Soft-deleted issues and comments are removed for good after 30 days (spec 5.1, leaning).
Schedule::command('issues:purge')->daily();

// Spec 7.5: a Close as Duplicate whose after-commit link failed gets it from the activity log.
Schedule::command('links:reconcile-duplicates')->daily();

// D2: workspaces scheduled for deletion go for good after 7 days.
Schedule::command('workspaces:purge')->daily();

// B-D5: agent runs keep full detail for 30 days, then who, when, the issue and tokens.
Schedule::command('agent-runs:prune')->daily();

// A2: approval requests expire after 7 days; the agent can ask again.
Schedule::command('approvals:expire')->hourly();

// 3-R19: a support session past its end gets its ended row even if the browser never comes back.
Schedule::command('support-sessions:end')->everyMinute()->onOneServer()->withoutOverlapping();

// 3-R7: each person with agents hears once a day, shortly after midnight server time, what their agents did the day before.
Schedule::command('notifications:agent-summary')->dailyAt('00:10')->onOneServer()->withoutOverlapping();

// 3-R21: tell Platform management which workspaces were active yesterday (does nothing without PM_SYSTEM_EVENTS_URL).
Schedule::command('system-events:activity')->dailyAt('00:20')->onOneServer()->withoutOverlapping();
// 3-R22: an outbox row whose delivery job never ran gets queued again (PR 49 review).
Schedule::call(fn () => app(SystemEventService::class)->dispatchPending())->name('system-events:pending')->everyTenMinutes()->onOneServer()->withoutOverlapping();
