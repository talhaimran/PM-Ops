<?php

use App\Models\Activity;
use App\Models\Issue;
use App\Models\User;
use App\Services\IssueService;
use App\Services\SprintService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Spec 7.4: entering a Done-category status always sets a resolution, leaving clears it, only Done counts as completed.

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->web = makeProject($this->admin, 'WEB');
    makeIssue($this->web, $this->admin, ['title' => 'one']);
    makeIssue($this->web, $this->admin, ['title' => 'two']);
    apiAsPerson($this->admin);
});

test('moving into a Done status resolves it as Done now; with a resolution it stores that', function () {
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertOk()
        ->assertJsonPath('data.resolution', 'done')
        ->assertJsonPath('data.resolved_at', fn ($at) => is_string($at));
    $this->patchJson('/api/v1/issues/WEB-2', ['status' => 'Done', 'resolution' => 'wont_do'])->assertOk()
        ->assertJsonPath('data.resolution', 'wont_do')
        ->assertJsonPath('data.category', 'done');

    expect(Issue::where('number', 1)->sole()->resolved_at->diffInSeconds(now(), true))->toBeLessThan(5)
        ->and(Issue::where('number', 2)->sole()->resolution->value)->toBe('wont_do');
});

test('moving out clears resolution and resolved_at and logs one reopened entry', function () {
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertOk();

    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'In Progress'])->assertOk()
        ->assertJsonPath('data.resolution', null)->assertJsonPath('data.resolved_at', null);

    $issue = Issue::where('number', 1)->sole();
    expect($issue->resolution)->toBeNull()->and($issue->resolved_at)->toBeNull()
        ->and(Activity::where('action', 'reopened')->count())->toBe(1)
        ->and(Activity::where('action', 'reopened')->sole()->changes)->toEqual([
            'status' => ['from' => 'Done', 'to' => 'In Progress'],
            'resolution' => ['from' => 'done', 'to' => null],
        ]);
});

test('a resolution needs a Done status', function () {
    $this->patchJson('/api/v1/issues/WEB-1', ['resolution' => 'wont_do'])->assertUnprocessable()
        ->assertJsonPath('errors.resolution.0', 'Resolution applies to Done statuses. Move the issue to one of: Done.');
    $this->patchJson('/api/v1/issues/WEB-1', ['status' => 'In Progress', 'resolution' => 'wont_do'])->assertUnprocessable();
    $this->patchJson('/api/v1/issues/WEB-1', ['resolution' => 'finished'])->assertUnprocessable()
        ->assertJsonPath('errors.resolution.0', 'The resolution must be one of: done, wont_do, duplicate, cannot_reproduce.');

    expect(Issue::where('number', 1)->sole()->status->name)->toBe('To Do');
});

test('Duplicate needs the original: unknown, itself and other workspaces are refused, a real one goes in the activity', function () {
    $this->patchJson('/api/v1/issues/WEB-1', ['status' => 'Done', 'resolution' => 'duplicate'])->assertUnprocessable()
        ->assertJsonPath('errors.duplicate_of.0', "Send duplicate_of with the original issue's key, e.g. WEB-3.");
    $this->patchJson('/api/v1/issues/WEB-1', ['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-99'])->assertUnprocessable()->assertJsonValidationErrors('duplicate_of');
    $this->patchJson('/api/v1/issues/WEB-1', ['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-1'])->assertUnprocessable()
        ->assertJsonPath('errors.duplicate_of.0', "An issue can't be a duplicate of itself.");
    $this->patchJson('/api/v1/issues/WEB-1', ['status' => 'Done', 'resolution' => 'wont_do', 'duplicate_of' => 'WEB-2'])->assertUnprocessable()->assertJsonValidationErrors('duplicate_of');
    expect(Issue::where('number', 1)->sole()->status->name)->toBe('To Do');

    $this->patchJson('/api/v1/issues/WEB-1', ['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'web-2'])->assertOk()
        ->assertJsonPath('data.resolution', 'duplicate');

    expect(Activity::where('action', 'updated')->latest('id')->first()->changes)->toMatchArray(['duplicate_of' => ['from' => null, 'to' => 'WEB-2']]);
});

test('changing the resolution of a Done issue keeps resolved_at', function () {
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertOk();
    $at = Issue::where('number', 1)->sole()->resolved_at;
    $this->travel(2)->hours();

    $this->patchJson('/api/v1/issues/WEB-1', ['resolution' => 'cannot_reproduce'])->assertOk()->assertJsonPath('data.resolution', 'cannot_reproduce');

    expect(Issue::where('number', 1)->sole()->resolved_at->equalTo($at))->toBeTrue();
});

test('1-R8: a project without a Done status says an Admin must add one', function () {
    $done = $this->web->columns()->where('name', 'Done')->sole();
    DB::table('column_statuses')->where('column_id', $done->id)->delete();
    $done->delete();

    $this->patchJson('/api/v1/issues/WEB-1', ['resolution' => 'wont_do'])->assertUnprocessable()
        ->assertJsonPath('errors.resolution.0', 'WEB has no Done status. A project Admin must add a column with a Done status before issues can be closed.');
});

test('a created issue in a Done status is resolved too', function () {
    $this->postJson('/api/v1/projects/WEB/issues', ['title' => 'late', 'status' => 'Done', 'resolution' => 'wont_do'])->assertCreated()
        ->assertJsonPath('data.resolution', 'wont_do');
    $this->postJson('/api/v1/projects/WEB/issues', ['title' => 'late 2', 'status' => 'Done'])->assertCreated()->assertJsonPath('data.resolution', 'done');
});

test('bulk updates resolve each item', function () {
    $this->patchJson('/api/v1/issues/bulk', ['updates' => [
        ['key' => 'WEB-1', 'status' => 'Done'],
        ['key' => 'WEB-2', 'status' => 'Done', 'resolution' => 'wont_do'],
    ]])->assertOk()->assertJsonPath('data.0.resolution', 'done')->assertJsonPath('data.1.resolution', 'wont_do');
    $this->patchJson('/api/v1/issues/bulk', ['updates' => [['key' => 'WEB-1', 'status' => 'To Do'], ['key' => 'WEB-2', 'status' => 'To Do', 'resolution' => 'done']]])
        ->assertUnprocessable()->assertJsonValidationErrors('updates.1.resolution');
});

test('sprint points count Done only, and completing carries over only issues whose category is not Done', function () {
    $sprints = app(SprintService::class);
    $sprint = $sprints->create($this->web, ['name' => 'S1']);
    foreach ([['done', 'Done', 3, null], ['skipped', 'Done', 5, 'wont_do'], ['open', 'In Progress', 8, null]] as [$title, $status, $points, $resolution]) {
        makeIssue($this->web, $this->admin, ['title' => $title, 'status' => $status, 'sprint' => 'S1', 'story_points' => $points, ...($resolution ? ['resolution' => $resolution] : [])]);
    }
    $sprints->start($sprint->setRelation('project', $this->web), []);

    $this->getJson('/api/v1/projects/WEB/sprints/S1')->assertOk()->assertJsonPath('data.points_done', 3)->assertJsonPath('data.points_total', 16);
    $this->getJson('/api/v1/projects/WEB/summary')->assertOk()->assertJsonPath('data.active_sprint.points_done', 3)->assertJsonPath('data.active_sprint.issues_done', 1);

    $this->postJson('/api/v1/projects/WEB/sprints/S1/complete', ['move_open_issues_to' => 'backlog', 'confirm' => true])->assertOk();

    // The Won't do issue is neither completed work nor carried over: it stays in the sprint, dimmed on its board.
    expect(Issue::where('title', 'skipped')->sole()->sprint_id)->toBe($sprint->id)
        ->and(Issue::where('title', 'done')->sole()->sprint_id)->toBe($sprint->id)
        ->and(Issue::where('title', 'open')->sole()->sprint_id)->toBeNull();
});

test('category and resolution filter list, search, board and my issues on REST and MCP', function () {
    makeIssue($this->web, $this->admin, ['title' => 'finished', 'status' => 'Done']);
    makeIssue($this->web, $this->admin, ['title' => 'dropped', 'status' => 'Done', 'resolution' => 'wont_do']);
    Issue::query()->update(['assignee_id' => $this->admin->id]);
    $titles = fn ($response) => collect($response->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

    expect($titles($this->getJson('/api/v1/projects/WEB/issues?category=done')))->toBe(['dropped', 'finished'])
        ->and($titles($this->getJson('/api/v1/projects/WEB/issues?resolution=wont_do')))->toBe(['dropped'])
        ->and($titles($this->getJson('/api/v1/issues?category=to_do')))->toBe(['one', 'two'])
        ->and($titles($this->getJson('/api/v1/issues?resolution=done')))->toBe(['finished'])
        ->and($titles($this->getJson('/api/v1/me/issues')))->toBe(['one', 'two'])
        ->and($titles($this->getJson('/api/v1/me/issues?category=done')))->toBe(['dropped', 'finished'])
        ->and($titles($this->getJson('/api/v1/me/issues?resolution=wont_do')))->toBe(['dropped']);

    $board = $this->getJson('/api/v1/projects/WEB/board?resolution=wont_do')->assertOk()->json('data.columns');
    expect(collect($board)->pluck('issues')->flatten(1)->pluck('title')->all())->toBe(['dropped'])
        ->and(collect($board)->pluck('issues')->flatten(1)->first())->toMatchArray(['category' => 'done', 'resolution' => 'wont_do']);
    $this->getJson('/api/v1/issues?category=finished')->assertUnprocessable()->assertJsonPath('errors.category.0', 'The category filter must be one of: to_do, in_progress, done.');

    [, $token] = makeAgent($this->admin);
    $mcp = fn (string $tool, array $args) => collect(($json = mcpJson($token, $tool, $args))['data'] ?? $json)->pluck('title')->sort()->values()->all();
    expect($mcp('search_issues', ['category' => 'done']))->toBe(['dropped', 'finished'])
        ->and($mcp('search_issues', ['resolution' => 'wont_do']))->toBe(['dropped'])
        ->and($mcp('my_issues', ['resolution' => 'wont_do']))->toBe(['dropped'])
        ->and($mcp('my_issues', []))->toBe(['one', 'two'])
        ->and(collect(mcpJson($token, 'get_board', ['project' => 'WEB', 'resolution' => 'wont_do'])['columns'])->pluck('issues')->flatten(1)->pluck('title')->all())->toBe(['dropped']);
});

test('web: undo of a move into Done restores the old status and clears the resolution', function () {
    $this->actingAs($this->admin);

    $this->post(w('/issues/WEB-1/move'), ['status' => 'Done'])->assertRedirect();
    expect(Issue::where('number', 1)->sole()->resolution?->value)->toBe('done');
    $undo = session('undo');

    $this->{$undo['method']}($undo['url'], $undo['data'])->assertSessionHasNoErrors();

    $issue = Issue::where('number', 1)->sole()->load('status');
    expect($issue->status->name)->toBe('To Do')->and($issue->resolution)->toBeNull()->and($issue->resolved_at)->toBeNull();
});

test('web: undo of a resolution change puts the old resolution back', function () {
    $this->actingAs($this->admin);
    $this->post(w('/issues/WEB-1/move'), ['status' => 'Done'])->assertRedirect();

    $this->patch(w('/issues/WEB-1'), ['resolution' => 'wont_do'])->assertSessionHasNoErrors();
    $undo = session('undo');
    expect(Issue::where('number', 1)->sole()->resolution->value)->toBe('wont_do');

    $this->{$undo['method']}($undo['url'], $undo['data'])->assertSessionHasNoErrors();

    expect(Issue::where('number', 1)->sole()->resolution->value)->toBe('done');
});

test('deleting a column moves its issues under the same rule: into Done they are resolved, out of it reopened', function () {
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertOk();

    $this->deleteJson('/api/v1/projects/WEB/columns/To%20Do', ['confirm' => true, 'move_issues_to' => 'Done'])->assertNoContent();
    expect(Issue::where('number', 2)->sole())->resolution->value->toBe('done');

    $this->deleteJson('/api/v1/projects/WEB/columns/Done', ['confirm' => true, 'move_issues_to' => 'In Progress'])->assertNoContent();
    expect(Issue::where('number', 1)->sole())->resolution->toBeNull()->resolved_at->toBeNull();
});

test('a resolution-only update reads the issue under the lock, not a stale copy', function () {
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertOk();
    $stale = Issue::where('number', 1)->sole()->load('status');
    app(IssueService::class)->update(Issue::where('number', 1)->sole(), $this->admin, ['status' => 'To Do']);

    expect(fn () => app(IssueService::class)->update($stale, $this->admin, ['resolution' => 'wont_do']))
        ->toThrow(ValidationException::class, 'Resolution applies to Done statuses.');
    expect(Issue::where('number', 1)->sole()->resolution)->toBeNull();
});

test('Undo out of Done leaves a reopened entry before the undid one', function () {
    $this->actingAs($this->admin);
    $this->post(w('/issues/WEB-1/move'), ['status' => 'Done'])->assertRedirect();
    $undo = session('undo');

    $this->{$undo['method']}($undo['url'], $undo['data'])->assertSessionHasNoErrors();

    expect(Activity::where('action', 'reopened')->count())->toBe(1)
        ->and(Activity::latest('id')->first()->action)->toBe('undid');
});

test('deleting a Done column logs a reopened entry for each issue it moves out of Done', function () {
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertOk();

    $this->deleteJson('/api/v1/projects/WEB/columns/Done', ['confirm' => true, 'move_issues_to' => 'In Progress'])->assertNoContent();

    $reopened = Activity::where('action', 'reopened')->sole();
    expect($reopened->user_id)->toBe($this->admin->id)
        ->and($reopened->changes)->toEqual(['status' => ['from' => 'Done', 'to' => 'In Progress'], 'resolution' => ['from' => 'done', 'to' => null]]);
});

test('duplicate_of alone on a move is the same 422 as on an update', function () {
    $this->postJson('/api/v1/issues/WEB-1/move', ['duplicate_of' => 'WEB-2'])->assertUnprocessable()->assertJsonValidationErrors('resolution');
    $this->patchJson('/api/v1/issues/WEB-1', ['duplicate_of' => 'WEB-2'])->assertUnprocessable()->assertJsonValidationErrors('resolution');
});

test('the web dashboard lists my issues not done by default and takes category and resolution', function () {
    makeIssue($this->web, $this->admin, ['title' => 'finished', 'status' => 'Done']);
    makeIssue($this->web, $this->admin, ['title' => 'dropped', 'status' => 'Done', 'resolution' => 'wont_do']);
    Issue::query()->update(['assignee_id' => $this->admin->id]);
    $this->actingAs($this->admin);
    $titles = fn (string $query) => collect($this->get(w("/dashboard{$query}"))->assertOk()->viewData('page')['props']['myIssues'])->pluck('title')->sort()->values()->all();

    expect($titles(''))->toBe(['one', 'two'])
        ->and($titles('?category=done'))->toBe(['dropped', 'finished'])
        ->and($titles('?resolution=wont_do'))->toBe(['dropped']);
    $this->get(w('/dashboard?category=finished'))->assertSessionHasErrors(['category' => 'The category filter must be one of: to_do, in_progress, done.']);
    $this->get(w('/dashboard?resolution=nope'))->assertSessionHasErrors('resolution');
});

test('the dashboard filters never show issues from projects the user cannot open', function () {
    $bob = User::factory()->create();
    $ops = makeProject($bob, 'OPS');
    makeIssue($ops, $bob, ['title' => 'secret', 'status' => 'Done']);
    Issue::query()->update(['assignee_id' => $this->admin->id]);
    asWorkspaceMember($this->admin);
    $this->actingAs($this->admin);

    $mine = collect($this->get(w('/dashboard?category=done'))->assertOk()->viewData('page')['props']['myIssues'])->pluck('title')->all();

    expect($mine)->not->toContain('secret');
});
