<?php

use App\Enums\WorkspaceRole;
use App\Events\ActivityRecorded;
use App\Events\AuditRecorded;
use App\Models\User;
use App\Services\IssueService;
use App\Services\WorkspaceMemberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// 3-R1: the two loggers emit one event per row, after commit.

beforeEach(function () {
    $this->ann = User::factory()->create();
    $this->project = makeProject($this->ann, 'WEB');
    $this->issue = makeIssue($this->project, $this->ann);
    $this->seen = collect();
    Event::listen(ActivityRecorded::class, fn ($e) => $this->seen->push($e));
    Event::listen(AuditRecorded::class, fn ($e) => $this->seen->push($e));
});

test('a change inside a transaction that rolls back dispatches nothing', function () {
    try {
        DB::transaction(function () {
            app(IssueService::class)->update($this->issue, $this->ann, ['title' => 'Renamed']);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect($this->seen)->toHaveCount(0);
});

test('a committed issue update dispatches one ActivityRecorded with its activity', function () {
    app(IssueService::class)->update($this->issue, $this->ann, ['title' => 'Renamed']);

    expect($this->seen)->toHaveCount(1)
        ->and($this->seen[0])->toBeInstanceOf(ActivityRecorded::class)
        ->and($this->seen[0]->activity->action)->toBe('updated')
        ->and($this->seen[0]->activity->issue_id)->toBe($this->issue->id);
});

test('an audited role change dispatches AuditRecorded', function () {
    $bob = User::factory()->create();
    $workspace = $this->project->workspace;
    $workspace->addMember($bob, WorkspaceRole::Member);
    $this->seen = collect();

    app(WorkspaceMemberService::class)->changeRole($workspace, $this->ann, $bob, WorkspaceRole::Admin);

    expect($this->seen)->toHaveCount(1)
        ->and($this->seen[0])->toBeInstanceOf(AuditRecorded::class)
        ->and($this->seen[0]->log->target_id)->toBe($bob->id);
});
