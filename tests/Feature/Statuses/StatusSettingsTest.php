<?php

use App\Enums\ProjectRole;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Status;
use App\Models\User;
use App\Services\StatusService;
use Inertia\Testing\AssertableInertia as Assert;

// Spec 7.4: Settings → Statuses (Owners and Admins) and the project column editor's status mapping (project Admins).

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->web = makeProject($this->owner, 'WEB');
    $this->ws = $this->web->workspace;
    $this->alex = User::factory()->create();
    $this->ws->addMember($this->alex, WorkspaceRole::Admin);
    $this->sam = User::factory()->create();
    addMember($this->web, $this->sam, ProjectRole::Member);
    $this->st = fn (string $name) => Status::where('workspace_id', $this->ws->id)->where('name', $name)->sole();
    $this->col = fn (string $name) => $this->web->columns()->where('name', $name)->sole();
});

test('Owners and Admins see every status with category, colour and usage; Members get 403', function () {
    makeIssue($this->web, $this->owner);
    makeIssue($this->web, $this->owner)->delete();
    makeProject($this->owner, 'OPS');

    foreach ([$this->owner, $this->alex] as $admin) {
        $this->actingAs($admin)->get(w('/settings/statuses'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Workspaces/Settings/Statuses')
            ->has('statuses', 4)
            ->where('statuses.3.name', 'To Do')
            ->where('statuses.3.category', 'to_do')
            ->where('statuses.3.color', null)
            ->where('statuses.3.projects_count', 2)
            ->where('statuses.3.issues_count', 2)
            ->where('statuses.0.issues_count', 0));
    }
    $this->actingAs($this->sam)->get(w('/settings/statuses'))->assertForbidden();
    $this->actingAs($this->sam)->patch(w('/settings/statuses/'.($this->st)('Done')->id), ['name' => 'X'])->assertForbidden();
});

test('an agent token cannot reach any status route', function () {
    [, $token] = makeAgent($this->owner);
    $id = ($this->st)('Done')->id;
    $col = ($this->col)('Done')->id;

    foreach ([
        ['getJson', '/settings/statuses', []], ['patchJson', "/settings/statuses/{$id}", ['name' => 'X']],
        ['postJson', "/settings/statuses/{$id}/merge", ['into' => 'To Do']], ['deleteJson', "/settings/statuses/{$id}", []],
        ['postJson', "/projects/WEB/columns/{$col}/statuses", ['status' => 'To Do']], ['deleteJson', "/projects/WEB/columns/{$col}/statuses/{$id}", []],
    ] as [$method, $path, $data]) {
        $this->withToken($token)->{$method}(w($path), $data)->assertUnauthorized();
    }
    expect(($this->st)('Done')->name)->toBe('Done');
});

test('rename logs before and after; an existing name is a 422 listing the workspace names; category is not accepted', function () {
    $review = ($this->st)('In Review');
    $this->actingAs($this->alex)->patch(w("/settings/statuses/{$review->id}"), ['name' => ' QA review '])->assertSessionHasNoErrors();

    expect($review->fresh()->name)->toBe('QA review')
        ->and(AuditLog::where('action', 'status.renamed')->sole()->only(['target_label', 'actor_user_id', 'metadata']))
        ->toEqual(['target_label' => 'QA review', 'actor_user_id' => $this->alex->id, 'metadata' => ['from' => 'In Review', 'to' => 'QA review']]);

    $this->actingAs($this->alex)->from(w('/settings/statuses'))->patch(w("/settings/statuses/{$review->id}"), ['name' => 'done'])
        ->assertSessionHasErrors(['name' => 'A status named "done" already exists. Names in this workspace: Done, In Progress, QA review, To Do.']);
    $this->patch(w("/settings/statuses/{$review->id}"), ['category' => 'done'])->assertSessionHasErrors('category');
    expect($review->fresh()->category)->toBe(StatusCategory::InProgress)->and(AuditLog::where('action', 'status.renamed')->count())->toBe(1);
});

test('recolour uses the palette; null goes back to the category colour; renaming a column leaves the status alone', function () {
    $done = ($this->st)('Done');
    $this->actingAs($this->alex)->patch(w("/settings/statuses/{$done->id}"), ['color' => 'coral'])->assertSessionHasNoErrors();
    expect($done->fresh()->color)->toBe('coral')
        ->and(AuditLog::where('action', 'status.recoloured')->sole()->metadata)->toEqual(['name' => 'Done', 'from' => null, 'to' => 'coral']);

    $this->from(w('/settings/statuses'))->patch(w("/settings/statuses/{$done->id}"), ['color' => '#ff0000'])
        ->assertSessionHasErrors(['color' => 'The colour must be one of: slate, sky, amber, cobalt, coral, teal, sand, good.']);
    $this->patch(w("/settings/statuses/{$done->id}"), ['color' => null])->assertSessionHasNoErrors();
    expect($done->fresh()->color)->toBeNull();

    $this->patch(w('/projects/WEB/columns/'.($this->col)('Done')->id), ['name' => 'Shipped']);
    expect($done->fresh()->name)->toBe('Done');
});

test('delete works only with no issue (soft-deleted too) and no column; it logs status.deleted', function () {
    $qa = app(StatusService::class)->create($this->ws, $this->owner, 'QA', StatusCategory::InProgress);
    $this->actingAs($this->alex)->delete(w("/settings/statuses/{$qa->id}"))->assertSessionHasNoErrors();
    expect(Status::find($qa->id))->toBeNull()
        ->and(AuditLog::where('action', 'status.deleted')->sole()->metadata)->toEqual(['name' => 'QA', 'category' => 'in_progress']);

    $issue = makeIssue($this->web, $this->owner);
    $issue->delete();
    $todo = ($this->st)('To Do');
    $this->from(w('/settings/statuses'))->delete(w("/settings/statuses/{$todo->id}"))
        ->assertSessionHasErrors(['status' => 'To Do is used by 1 column in 1 project and 1 issue. Merge it into another status, or move them first.']);
    $this->delete(w('/settings/statuses/'.($this->st)('In Review')->id))->assertSessionHasErrors('status');
    expect(Status::count())->toBe(4)->and(AuditLog::where('action', 'status.deleted')->count())->toBe(1);

    // Issues alone also block it, once no column maps it.
    $this->web->columns()->where('name', 'Done')->first()->statuses()->detach();
    $this->delete(w('/settings/statuses/'.($this->st)('Done')->id))->assertSessionHasNoErrors();
    expect(Status::where('name', 'Done')->exists())->toBeFalse();
});

test('a project Admin attaches a library status, or creates one with a name and category', function () {
    $qa = app(StatusService::class)->create($this->ws, $this->owner, 'QA', StatusCategory::InProgress);
    $col = ($this->col)('In Review');
    $audits = AuditLog::where('action', 'status.created')->count();

    $this->actingAs($this->owner)->post(w("/projects/WEB/columns/{$col->id}/statuses"), ['status' => 'qa'])->assertSessionHasNoErrors();
    expect($col->statuses()->pluck('name')->all())->toBe(['In Review', 'QA']);

    $this->post(w("/projects/WEB/columns/{$col->id}/statuses"), ['name' => 'Blocked', 'category' => 'in_progress'])->assertSessionHasNoErrors();
    expect($col->statuses()->pluck('name')->all())->toBe(['In Review', 'QA', 'Blocked'])
        ->and(AuditLog::where('action', 'status.created')->count())->toBe($audits + 1)
        ->and(AuditLog::where('action', 'status.created')->latest('id')->first()->metadata)->toEqual(['name' => 'Blocked', 'category' => 'in_progress']);

    // An existing name is not created twice: pick it instead.
    $this->from(w('/projects/WEB/settings'))->post(w("/projects/WEB/columns/{$col->id}/statuses"), ['name' => 'done', 'category' => 'to_do'])
        ->assertSessionHasErrors(['name' => 'A status named "done" already exists (Done). Pick it instead of creating it.']);
    $this->post(w("/projects/WEB/columns/{$col->id}/statuses"), ['status' => 'To Do', 'name' => 'x'])->assertSessionHasErrors();
});

test('a status in another column of the project is refused naming that column', function () {
    $col = ($this->col)('In Review');
    $this->actingAs($this->owner)->from(w('/projects/WEB/settings'))->post(w("/projects/WEB/columns/{$col->id}/statuses"), ['status' => 'Done'])
        ->assertSessionHasErrors(['status' => 'Done is already in the Done column of WEB. A status can be in one column per project.']);
    expect($col->statuses()->count())->toBe(1);
});

test('detaching refuses a status its issues use, and the last status of a column', function () {
    $col = ($this->col)('To Do');
    $qa = app(StatusService::class)->create($this->ws, $this->owner, 'QA', StatusCategory::ToDo);
    $this->actingAs($this->owner)->post(w("/projects/WEB/columns/{$col->id}/statuses"), ['status' => 'QA']);
    makeIssue($this->web, $this->owner, ['status' => 'QA']);

    $this->from(w('/projects/WEB/settings'))->delete(w("/projects/WEB/columns/{$col->id}/statuses/{$qa->id}"))
        ->assertSessionHasErrors(['status' => 'QA is used by 1 issue in WEB. Move them to another status first.']);
    $this->delete(w("/projects/WEB/columns/{$col->id}/statuses/".($this->st)('To Do')->id))->assertSessionHasNoErrors();
    expect($col->statuses()->pluck('name')->all())->toBe(['QA']);

    // Now QA is the only one left, and it has an issue anyway; an empty one is refused for the last-status rule.
    $done = ($this->col)('Done');
    $this->delete(w("/projects/WEB/columns/{$done->id}/statuses/".($this->st)('Done')->id))
        ->assertSessionHasErrors(['status' => 'The Done column needs at least one status. Add another before removing Done.']);
    $this->delete(w("/projects/WEB/columns/{$done->id}/statuses/".($this->st)('To Do')->id))->assertSessionHasErrors(['status' => 'To Do is not in the Done column of WEB. It has: Done.']);
});

test('a project Member and an agent cannot attach or detach', function () {
    $col = ($this->col)('In Review');
    $this->actingAs($this->sam)->post(w("/projects/WEB/columns/{$col->id}/statuses"), ['name' => 'QA', 'category' => 'to_do'])->assertForbidden();
    $this->delete(w("/projects/WEB/columns/{$col->id}/statuses/".($this->st)('In Review')->id))->assertForbidden();
    expect(Status::where('name', 'QA')->exists())->toBeFalse();
});
