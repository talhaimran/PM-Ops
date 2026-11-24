<?php

use App\Models\Issue;
use App\Models\User;
use App\Services\IssueService;
use App\Services\LinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Review S1: the successors to lock are read before the transaction; a link another request commits in between is
// found again after the locks are taken, and the update is refused (409) instead of shifting an issue nobody locked.

afterEach(function () {
    $this->locker->transactionLevel() && $this->locker->rollBack();
});

test('a precedes link committed between the read and the locks refuses the update with a 409 and saves nothing', function () {
    $owner = User::factory()->create(['date_shift' => 'always']);
    makeWorkspace($owner, 'acme');
    $web = makeProject($owner, 'WEB');
    makeIssue($web, $owner, ['title' => 'One', 'due_date' => '2026-12-09']);
    makeIssue($web, $owner, ['title' => 'Two', 'start_date' => '2026-12-10', 'due_date' => '2026-12-12']);
    makeIssue($web, $owner, ['title' => 'Three', 'start_date' => '2026-12-10', 'due_date' => '2026-12-12']);
    $issue = fn (int $n) => Issue::where('number', $n)->with('project')->sole();
    app(LinkService::class)->link($issue(1), 'precedes', $issue(2), $owner);
    config(['database.connections.locker' => config('database.connections.'.config('database.default'))]);
    $this->locker = DB::connection('locker');

    $done = false;
    DB::listen(function ($query) use (&$done, $issue) {
        // Right after the first read of WEB-1's successors, another request commits a link to WEB-3.
        if (! $done && $query->connectionName !== 'locker' && str_contains($query->sql, 'from `issue_links` where `type` = ? and `source_issue_id` in')) {
            $done = true;
            $this->locker->table('issue_links')->insert(['source_issue_id' => $issue(1)->id, 'target_issue_id' => $issue(3)->id, 'type' => 'precedes', 'via' => 'web']);
        }
    });

    expect(fn () => app(IssueService::class)->update($issue(1), $owner, ['due_date' => '2026-12-14']))
        ->toThrow(ValidationException::class, 'Links or dates changed while shifting. Try again.');

    expect($issue(1)->due_date->format('Y-m-d'))->toBe('2026-12-09')->and($issue(2)->start_date->format('Y-m-d'))->toBe('2026-12-10')->and($issue(3)->version)->toBe(1);
});

test('a shifting update waits for the workspace link lock and is refused when it times out; a link made meanwhile is shifted afterwards', function () {
    $owner = User::factory()->create(['date_shift' => 'always']);
    $workspace = makeWorkspace($owner, 'acme');
    $web = makeProject($owner, 'WEB');
    makeIssue($web, $owner, ['title' => 'One', 'due_date' => '2026-12-09']);
    makeIssue($web, $owner, ['title' => 'Two', 'start_date' => '2026-12-10', 'due_date' => '2026-12-12']);
    makeIssue($web, $owner, ['title' => 'Three', 'start_date' => '2026-12-10', 'due_date' => '2026-12-12']);
    $issue = fn (int $n) => Issue::where('number', $n)->with('project')->sole();
    $links = app(LinkService::class);
    $links->link($issue(1), 'precedes', $issue(2), $owner);
    config(['database.connections.locker' => config('database.connections.'.config('database.default')), 'pm.link_lock_timeout' => 1]);
    $this->locker = DB::connection('locker');
    $name = "pm:links:{$workspace->id}";

    // Another request is adding a precedes link (it holds the lock).
    $this->locker->select('SELECT GET_LOCK(?, 0)', [$name]);
    expect(fn () => app(IssueService::class)->update($issue(1), $owner, ['due_date' => '2026-12-14']))
        ->toThrow(ValidationException::class, 'Links are being changed in this workspace; try again.');
    expect($issue(1)->due_date->format('Y-m-d'))->toBe('2026-12-09');
    // The other way round: while a shift holds the lock, a link waits (and times out the same way).
    expect(fn () => $links->link($issue(1), 'precedes', $issue(3), $owner))->toThrow(ValidationException::class, 'Links are being changed in this workspace; try again.');
    $this->locker->select('SELECT RELEASE_LOCK(?)', [$name]);

    // The link goes in, and the next shift sees it.
    $links->link($issue(1), 'precedes', $issue(3), $owner);
    app(IssueService::class)->update($issue(1), $owner, ['due_date' => '2026-12-14']);
    expect($issue(2)->start_date->format('Y-m-d'))->toBe('2026-12-14')->and($issue(3)->start_date->format('Y-m-d'))->toBe('2026-12-14');
});
