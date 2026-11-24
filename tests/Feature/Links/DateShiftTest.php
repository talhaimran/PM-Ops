<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\UpdateIssue;
use App\Models\Activity;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\User;
use App\Services\LinkService;

// Spec 7.5, 2-R5, 2-R6, 2-R9: start_date, and moving a predecessor's due date shifts the successors that would conflict.

beforeEach(function () {
    $this->admin = User::factory()->create(['email' => 'admin@example.com']);
    $this->web = makeProject($this->admin, 'WEB');
    // WEB-1 (due the 9th) precedes WEB-2 (10th to 12th); WEB-2 precedes WEB-3 (13th to 14th).
    makeIssue($this->web, $this->admin, ['title' => 'One', 'due_date' => '2026-12-09']);
    makeIssue($this->web, $this->admin, ['title' => 'Two', 'start_date' => '2026-12-10', 'due_date' => '2026-12-12']);
    makeIssue($this->web, $this->admin, ['title' => 'Three', 'start_date' => '2026-12-13', 'due_date' => '2026-12-14']);
    foreach (range(4, 5) as $n) {
        makeIssue($this->web, $this->admin, ['title' => "Issue {$n}"]);
    }
    $this->issue = fn (int $n, string $project = 'WEB') => Issue::whereHas('project', fn ($p) => $p->where('key', $project))->where('number', $n)->with('project')->sole();
    $this->dates = fn (int $n, string $project = 'WEB') => [($this->issue)($n, $project)->start_date?->format('Y-m-d'), ($this->issue)($n, $project)->due_date?->format('Y-m-d')];
    $this->pref = function (string $pref) {
        $this->admin->forceFill(['date_shift' => $pref])->save();
    };
    $this->precedes = function (int $from, int $to) {
        app(LinkService::class)->link(($this->issue)($from), 'precedes', ($this->issue)($to), $this->admin);
    };
    ($this->precedes)(1, 2);
    apiAsPerson($this->admin);
});

test('start_date is saved, shown, and may not be after the due date', function () {
    $this->patchJson('/api/v1/issues/WEB-4', ['version' => 1, 'start_date' => '2026-12-01'])->assertOk()->assertJsonPath('data.start_date', '2026-12-01');
    $this->patchJson('/api/v1/issues/WEB-4', ['version' => 2, 'due_date' => '2026-11-30'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    $this->patchJson('/api/v1/issues/WEB-4', ['version' => 2, 'start_date' => '2026-12-05', 'due_date' => '2026-12-04'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    $this->patchJson('/api/v1/issues/WEB-4', ['version' => 2, 'start_date' => 'soon'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    $this->postJson('/api/v1/projects/WEB/issues', ['title' => 'New', 'start_date' => '2026-12-05', 'due_date' => '2026-12-04'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    expect(($this->dates)(4))->toBe(['2026-12-01', null]);

    $this->patchJson('/api/v1/issues/WEB-4', ['version' => 2, 'start_date' => null])->assertOk()->assertJsonPath('data.start_date', null);
});

test('always: a conflicting successor moves later by just enough, keeps its duration, and its activity says why', function () {
    ($this->pref)('always');

    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14'])->assertOk()->assertJsonMissingPath('proposed_shifts');

    expect(($this->dates)(2))->toBe(['2026-12-14', '2026-12-16'])
        ->and(($this->issue)(2)->version)->toBe(2)
        // WEB-3 follows nothing, so it stays.
        ->and(($this->dates)(3))->toBe(['2026-12-13', '2026-12-14']);
    $entry = Activity::where('issue_id', ($this->issue)(2)->id)->where('action', 'updated')->latest('id')->first();
    expect($entry->user_id)->toBe($this->admin->id)->and($entry->via)->toBe('web')
        ->and($entry->changes['start_date'])->toEqual(['from' => '2026-12-10', 'to' => '2026-12-14'])
        ->and($entry->changes['due_date'])->toEqual(['from' => '2026-12-12', 'to' => '2026-12-16'])
        ->and($entry->changes['shifted_by'])->toEqual(['from' => null, 'to' => 'WEB-1']);
    $this->getJson('/api/v1/issues/WEB-2/activity')->assertOk()->assertJsonPath('data.0.changes.shifted_by.to', 'WEB-1');
});

test('a chain WEB-1, WEB-2, WEB-3 shifts both successors', function () {
    ($this->precedes)(2, 3);
    ($this->pref)('always');

    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14'])->assertOk();

    expect(($this->dates)(2))->toBe(['2026-12-14', '2026-12-16'])
        // 13th to 14th, pushed to start when WEB-2 is due (the 16th): 16th to 17th.
        ->and(($this->dates)(3))->toBe(['2026-12-16', '2026-12-17'])
        ->and(Activity::where('issue_id', ($this->issue)(3)->id)->latest('id')->first()->changes['shifted_by']['to'])->toBe('WEB-1');
});

test('a successor that does not conflict stays, and so does everything behind it', function () {
    ($this->precedes)(2, 3);
    ($this->pref)('always');

    // Due the 10th: WEB-2 starts the 10th, which is not before it.
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-10'])->assertOk();

    expect(($this->dates)(2))->toBe(['2026-12-10', '2026-12-12'])->and(($this->dates)(3))->toBe(['2026-12-13', '2026-12-14']);
});

test('a resolved successor never moves, and the chain does not go on through it', function () {
    ($this->precedes)(2, 3);
    ($this->pref)('always');
    $this->patchJson('/api/v1/issues/WEB-2', ['version' => 1, 'status' => 'Done'])->assertOk();

    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-20'])->assertOk();

    expect(($this->dates)(2))->toBe(['2026-12-10', '2026-12-12'])->and(($this->dates)(3))->toBe(['2026-12-13', '2026-12-14']);
});

test('a loop in the data does not hang the walk', function () {
    ($this->precedes)(2, 3);
    // The service refuses loops; this one is written straight into the table (a race, or old data).
    IssueLink::create(['source_issue_id' => ($this->issue)(3)->id, 'target_issue_id' => ($this->issue)(2)->id, 'type' => 'precedes', 'via' => 'api']);
    IssueLink::create(['source_issue_id' => ($this->issue)(3)->id, 'target_issue_id' => ($this->issue)(1)->id, 'type' => 'precedes', 'via' => 'api']);
    ($this->pref)('always');

    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14'])->assertOk();

    expect(($this->dates)(1))->toBe([null, '2026-12-14']);
});

test('never saves the date and shifts nothing, and says nothing about it', function () {
    ($this->pref)('never');

    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14'])->assertOk()->assertJsonMissingPath('proposed_shifts');

    expect(($this->dates)(1))->toBe([null, '2026-12-14'])->and(($this->dates)(2))->toBe(['2026-12-10', '2026-12-12']);
});

test('ask (the default) saves the date, shifts nothing and lists the proposed dates as a warning', function () {
    ($this->precedes)(2, 3);

    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14'])->assertOk()
        ->assertJsonPath('data.due_date', '2026-12-14')
        ->assertJsonPath('data.proposed_shifts', [
            ['key' => 'WEB-2', 'start_date' => '2026-12-14', 'due_date' => '2026-12-16', 'note' => null],
            ['key' => 'WEB-3', 'start_date' => '2026-12-16', 'due_date' => '2026-12-17', 'note' => null],
        ])
        ->assertJsonPath('data.warning', fn ($w) => str_contains($w, 'WEB-2') && str_contains($w, 'shift_successors: true') && str_contains($w, '2026-12-16'));

    expect(($this->dates)(2))->toBe(['2026-12-10', '2026-12-12'])->and(($this->issue)(2)->version)->toBe(1);
});

test('ask says nothing when nothing conflicts or the due date did not change', function () {
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-09'])->assertOk()->assertJsonMissingPath('data.proposed_shifts');
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-05'])->assertOk()->assertJsonMissingPath('data.proposed_shifts');
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 2, 'title' => 'Renamed'])->assertOk()->assertJsonMissingPath('data.proposed_shifts');
});

test('shift_successors: true overrides never and applies what ask proposed; false overrides always', function () {
    ($this->pref)('never');
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14', 'shift_successors' => true])->assertOk();
    expect(($this->dates)(2))->toBe(['2026-12-14', '2026-12-16']);

    // Ask, then the agent calls again with true and no other change: the saved date is enough.
    ($this->pref)('ask');
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 2, 'due_date' => '2026-12-20'])->assertOk()->assertJsonCount(1, 'data.proposed_shifts');
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 3, 'shift_successors' => true])->assertOk()->assertJsonMissingPath('data.proposed_shifts');
    expect(($this->dates)(2))->toBe(['2026-12-20', '2026-12-22']);

    ($this->pref)('always');
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 3, 'due_date' => '2026-12-30', 'shift_successors' => false])->assertOk();
    expect(($this->dates)(2))->toBe(['2026-12-20', '2026-12-22']);
});

test('a successor without a start date is checked by its due date (2-R5), and with neither there is nothing to check', function () {
    makeIssue($this->web, $this->admin, ['title' => 'Due only', 'due_date' => '2026-12-12']);
    ($this->precedes)(1, 6);
    ($this->precedes)(1, 4);
    ($this->pref)('always');

    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14'])->assertOk();

    expect(($this->dates)(6))->toBe([null, '2026-12-14'])->and(($this->dates)(4))->toBe([null, null]);
});

test('an agent follows its owner\'s saved choice, and MCP takes shift_successors', function () {
    ($this->pref)('always');
    apiAs($this->admin, AgentLevel::Write);
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14'])->assertOk();
    expect(($this->dates)(2))->toBe(['2026-12-14', '2026-12-16']);

    ($this->pref)('ask');
    PmServer::tool(UpdateIssue::class, ['issue' => 'WEB-1', 'version' => 2, 'due_date' => '2026-12-20'])->assertOk()->assertSee('proposed_shifts');
    expect(($this->dates)(2))->toBe(['2026-12-14', '2026-12-16']);
    PmServer::tool(UpdateIssue::class, ['issue' => 'WEB-1', 'version' => 3, 'shift_successors' => true])->assertOk();
    expect(($this->dates)(2))->toBe(['2026-12-20', '2026-12-22']);
});

test('only successors the actor can edit are moved (viewer, hidden)', function () {
    $ops = makeProject($this->admin, 'OPS');
    $docs = makeProject($this->admin, 'DOCS');
    makeIssue($ops, $this->admin, ['title' => 'Ops', 'start_date' => '2026-12-10', 'due_date' => '2026-12-12']);
    makeIssue($ops, $this->admin, ['title' => 'Ops after', 'start_date' => '2026-12-13', 'due_date' => '2026-12-14']);
    makeIssue($docs, $this->admin, ['title' => 'Docs', 'start_date' => '2026-12-10', 'due_date' => '2026-12-12']);
    $links = app(LinkService::class);
    $links->link(($this->issue)(1), 'precedes', ($this->issue)(1, 'OPS'), $this->admin);
    $links->link(($this->issue)(1, 'OPS'), 'precedes', ($this->issue)(2, 'OPS'), $this->admin);
    $links->link(($this->issue)(1), 'precedes', ($this->issue)(1, 'DOCS'), $this->admin);
    // A contributor to WEB, a Viewer in OPS and no access to DOCS.
    $person = addMember($this->web, User::factory()->create(), ProjectRole::Member);
    addMember($ops, $person, ProjectRole::Viewer);
    $person->forceFill(['date_shift' => 'always'])->save();
    apiAsPerson($person);

    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14'])->assertOk()->assertJsonMissingPath('data.proposed_shifts');

    // WEB-2 moved. OPS-1 can't be edited by them and DOCS-1 can't be seen: neither moved, and nothing went through OPS-1.
    expect(($this->dates)(2))->toBe(['2026-12-14', '2026-12-16'])
        ->and(($this->dates)(1, 'OPS'))->toBe(['2026-12-10', '2026-12-12'])
        ->and(($this->dates)(2, 'OPS'))->toBe(['2026-12-13', '2026-12-14'])
        ->and(($this->dates)(1, 'DOCS'))->toBe(['2026-12-10', '2026-12-12']);

    // With "ask" the answer lists OPS-1 as one they can't shift, and never names DOCS-1.
    $person->forceFill(['date_shift' => 'ask'])->save();
    $response = $this->patchJson('/api/v1/issues/WEB-1', ['version' => 2, 'due_date' => '2026-12-30'])->assertOk()
        ->assertJsonPath('data.proposed_shifts.1', ['key' => 'OPS-1', 'start_date' => '2026-12-30', 'due_date' => '2027-01-01', 'note' => "can't shift: you can't edit OPS-1"]);
    expect($response->json('data.proposed_shifts'))->toHaveCount(2)->and($response->getContent())->not->toContain('DOCS');
});

test('the schedule warning is on the single issue, on both issues, and not on list rows', function () {
    $this->getJson('/api/v1/issues/WEB-2')->assertOk()->assertJsonPath('data.schedule_warning', null);
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14'])->assertOk();

    $sentence = 'WEB-2 starts on 2026-12-10, before WEB-1 is due on 2026-12-14.';
    $this->getJson('/api/v1/issues/WEB-2')->assertOk()->assertJsonPath('data.schedule_warning', $sentence)->assertJsonPath('data.start_date', '2026-12-10');
    $this->getJson('/api/v1/issues/WEB-1')->assertOk()->assertJsonPath('data.schedule_warning', $sentence);
    $this->getJson('/api/v1/issues')->assertOk()->assertJsonMissingPath('data.0.schedule_warning');

    // Resolved issues have nothing left to schedule.
    $this->patchJson('/api/v1/issues/WEB-2', ['version' => 1, 'status' => 'Done'])->assertOk();
    $this->getJson('/api/v1/issues/WEB-1')->assertOk()->assertJsonPath('data.schedule_warning', null);
});

test('web: ask flashes the proposal; "Remember my choice" saves date_shift, and the profile page sets it', function () {
    $this->actingAs($this->admin);

    $this->patch(w('/issues/WEB-1'), ['version' => 1, 'due_date' => '2026-12-14'])
        ->assertSessionHas('dateShift', fn ($shift) => $shift['key'] === 'WEB-1' && $shift['shifts'][0]['key'] === 'WEB-2');
    expect(($this->dates)(2))->toBe(['2026-12-10', '2026-12-12'])->and($this->admin->fresh()->date_shift)->toBe('ask');

    // "Shift dates" with "Remember my choice".
    $this->patch(w('/issues/WEB-1'), ['version' => 2, 'shift_successors' => true, 'remember_date_shift' => true])->assertSessionHasNoErrors();
    expect(($this->dates)(2))->toBe(['2026-12-14', '2026-12-16'])->and($this->admin->fresh()->date_shift)->toBe('always');

    // "Keep dates" with "Remember my choice".
    $this->patch(w('/issues/WEB-1'), ['version' => 2, 'shift_successors' => false, 'remember_date_shift' => true])->assertSessionHasNoErrors();
    expect($this->admin->fresh()->date_shift)->toBe('never');

    // Without it, the choice is for this once.
    $this->patch(w('/issues/WEB-1'), ['version' => 2, 'shift_successors' => true])->assertSessionHasNoErrors();
    expect($this->admin->fresh()->date_shift)->toBe('never');

    $this->patch(route('profile.update'), ['name' => 'Admin', 'email' => 'admin@example.com', 'date_shift' => 'ask'])->assertSessionHasNoErrors();
    expect($this->admin->fresh()->date_shift)->toBe('ask');
    $this->patch(route('profile.update'), ['name' => 'Admin', 'email' => 'admin@example.com', 'date_shift' => 'sometimes'])->assertSessionHasErrors('date_shift');
    $this->get(route('profile.edit'))->assertOk();
});

test('the "because WEB-1 moved" note is hidden from someone who can\'t see WEB-1, and no caller can write it', function () {
    $ops = makeProject($this->admin, 'OPS');
    makeIssue($ops, $this->admin, ['title' => 'Ops', 'start_date' => '2026-12-10', 'due_date' => '2026-12-12']);
    app(LinkService::class)->link(($this->issue)(1), 'precedes', ($this->issue)(1, 'OPS'), $this->admin);
    ($this->pref)('always');
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'due_date' => '2026-12-14', 'shifted_by' => 'FAKE-1'])->assertOk();
    $this->patchJson('/api/v1/issues/WEB-4', ['version' => 1, 'title' => 'Plain', 'shifted_by' => 'FAKE-1'])->assertOk();
    expect(Activity::where('issue_id', ($this->issue)(4)->id)->latest('id')->first()->changes)->not->toHaveKey('shifted_by');

    $this->getJson('/api/v1/issues/OPS-1/activity')->assertJsonPath('data.0.changes.shifted_by.to', 'WEB-1');
    $person = addMember($ops, User::factory()->create(), ProjectRole::Member);
    apiAsPerson($person);
    $this->getJson('/api/v1/issues/OPS-1/activity')->assertOk()->assertJsonPath('data.0.changes.start_date.to', '2026-12-14')->assertJsonMissingPath('data.0.changes.shifted_by');
});
