<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\MyIssues;
use App\Models\Issue;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\LinkService;
use App\Services\StatusService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Spec 5.1 (2-R8): My issues "Focus" grouping. Each open issue is in the first group it qualifies for.

beforeEach(function () {
    $this->me = User::factory()->create(['email' => 'me@example.com']);
    $this->project = makeProject($this->me, 'WEB');
    $this->mine = fn (array $data = []) => makeIssue($this->project, $this->me, ['assignee' => 'me@example.com', ...$data]);
    $this->focus = fn () => collect($this->actingAs($this->me)->getJson('/api/v1/me/issues')->assertOk()->json('data'))->pluck('focus', 'key')->all();
});

test('each open issue lands in the first group it qualifies for', function () {
    $waiting = ($this->mine)(['title' => 'Waiting']);
    [$agent] = makeAgent($this->me, AgentLevel::Write);
    app(ApprovalService::class)->requestDone($waiting->setRelation('project', $this->project), $this->me, $agent, app(StatusService::class)->resolveFor($this->project, 'Done'), null);
    $blocking = ($this->mine)(['title' => 'Blocking', 'due_date' => today()->subDay()->toDateString()]); // also overdue
    $urgent = ($this->mine)(['priority' => 'highest']);
    $overdue = ($this->mine)(['due_date' => today()->subDay()->toDateString()]);
    $soon = ($this->mine)(['due_date' => today()->addDays(3)->toDateString()]);
    $later = ($this->mine)(['due_date' => today()->addDays(4)->toDateString()]);
    $started = ($this->mine)(['status' => 'In Progress']);
    $plain = ($this->mine)();
    $done = ($this->mine)(['status' => 'Done']);
    $target = makeIssue($this->project, $this->me, ['title' => 'Blocked by someone']);
    app(LinkService::class)->link($blocking, 'blocks', $target, $this->me);
    // A blocked target that is already resolved doesn't count.
    $resolved = makeIssue($this->project, $this->me, ['status' => 'Done']);
    app(LinkService::class)->link($plain, 'blocks', $resolved, $this->me);

    $focus = ($this->focus)();

    expect($focus[$waiting->key])->toBe('waiting_on_you')
        ->and($focus[$blocking->key])->toBe('blocking_others')
        ->and($focus[$urgent->key])->toBe('urgent_or_overdue')
        ->and($focus[$overdue->key])->toBe('urgent_or_overdue')
        ->and($focus[$soon->key])->toBe('due_soon')
        ->and($focus[$later->key])->toBe('to_do')
        ->and($focus[$started->key])->toBe('in_progress')
        ->and($focus[$plain->key])->toBe('to_do')
        ->and($focus)->not->toHaveKey($done->key);
});

test('a done issue shown by a filter has no focus group', function () {
    $done = ($this->mine)(['status' => 'Done']);

    $rows = $this->actingAs($this->me)->getJson('/api/v1/me/issues?category=done')->assertOk()->json('data');

    expect($rows[0]['key'])->toBe($done->key)->and($rows[0]['focus'])->toBeNull();
});

test('an issue that blocks only a hidden issue is not "blocking others"', function () {
    // $member is a plain workspace member: Owners and Admins would see SEC read-only.
    $member = asWorkspaceMember(addMember($this->project, User::factory()->create(), ProjectRole::Member));
    $hidden = makeIssue(makeProject($this->me, 'SEC'), $this->me, ['title' => 'Hidden']);
    $mine = makeIssue($this->project, $this->me, ['assignee' => $member->email]);
    DB::table('issue_links')->insert(['source_issue_id' => $mine->id, 'target_issue_id' => $hidden->id, 'type' => 'blocks', 'via' => 'web', 'created_at' => now()]);

    $this->me = $member;

    expect(($this->focus)()[$mine->key])->toBe('to_do');
});

test('another person\'s agent request on my issue is not waiting on me', function () {
    $other = asWorkspaceMember(addMember($this->project, User::factory()->create(), ProjectRole::Member));
    $issue = ($this->mine)();
    [$agent] = makeAgent($other, AgentLevel::Write);
    app(ApprovalService::class)->requestDone($issue->setRelation('project', $this->project), $other, $agent, app(StatusService::class)->resolveFor($this->project, 'Done'), null);

    expect(($this->focus)()[$issue->key])->toBe('to_do');
});

test('my_issues over mcp carries focus on each row', function () {
    ($this->mine)(['priority' => 'highest']);
    apiAs($this->me);

    PmServer::tool(MyIssues::class)->assertOk()->assertSee('"focus":"urgent_or_overdue"');
});

test('other lists do not carry focus', function () {
    ($this->mine)();

    $this->actingAs($this->me)->getJson('/api/v1/issues')->assertOk()->assertJsonMissingPath('data.0.focus');
});

test('computing focus costs the same queries for 5 issues as for 50', function () {
    $count = function () {
        DB::enableQueryLog();
        $this->actingAs($this->me)->getJson('/api/v1/me/issues')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $n;
    };
    foreach (range(1, 5) as $i) {
        ($this->mine)(['priority' => 'highest']);
    }
    $few = $count();
    foreach (range(1, 45) as $i) {
        ($this->mine)(['due_date' => today()->addDay()->toDateString()]);
    }

    expect(Issue::count())->toBe(50)->and($count())->toBe($few);
});

test('the grouping choice is remembered per person and validated', function () {
    $this->actingAs($this->me);
    $page = fn () => $this->get(w('/dashboard'))->assertOk();

    $page()->assertInertia(fn (Assert $p) => $p->where('group', 'focus'));
    foreach (['status', 'priority', 'project', 'sprint'] as $group) {
        $this->patch('/profile', ['my_issues_group' => $group])->assertSessionHasNoErrors();
        $page()->assertInertia(fn (Assert $p) => $p->where('group', $group));
    }
    $this->patch('/profile', ['my_issues_group' => 'nope'])->assertSessionHasErrors('my_issues_group');
    $this->patch('/profile', ['my_issues_group' => 'focus'])->assertSessionHasNoErrors();
    expect($this->me->fresh()->my_issues_group)->toBeNull();
    $page()->assertInertia(fn (Assert $p) => $p->where('group', 'focus'));
});
