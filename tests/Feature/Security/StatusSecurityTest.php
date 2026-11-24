<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Enums\StatusCategory;
use App\Models\AuditLog;
use App\Models\Issue;
use App\Models\Status;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\StatusService;
use Illuminate\Support\Facades\DB;

// Review Focus 4: another workspace's status is never accepted, and errors don't reveal that it exists.

beforeEach(function () {
    $this->ann = User::factory()->create();
    // Workspace B has a status A doesn't: "B-SECRET".
    $this->b = makeWorkspace($this->ann, 'bravo');
    makeProject($this->ann, 'OPS');
    apiAsPerson($this->ann);
    $this->postJson('/api/v1/projects/OPS/columns', ['name' => 'B-SECRET', 'category' => 'done'])->assertCreated();
    $this->secret = Status::where('name', 'B-SECRET')->sole();

    $this->a = makeWorkspace($this->ann, 'alpha');
    $this->web = makeProject($this->ann, 'WEB');
    makeIssue($this->web, $this->ann);
    apiAs($this->ann);
});

test("B's status name gets the same Unknown status error as a missing one, listing A's statuses only", function (string $name) {
    $valid = 'Valid statuses for WEB: To Do, In Progress, In Review, Done.';

    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'status' => $name])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', "Unknown status \"{$name}\". {$valid}");
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => $name])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', "Unknown status \"{$name}\". {$valid}");
    $this->postJson('/api/v1/projects/WEB/issues', ['title' => 'x', 'status' => $name])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', "Unknown status \"{$name}\". {$valid}");

    expect(Issue::sole()->status->name)->toBe('To Do');
})->with(['B-SECRET', 'Nope']);

test("B's status name can't be mapped to an A column, and the error lists A's library only", function () {
    apiAsPerson($this->ann);

    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'Ship', 'status' => 'B-SECRET'])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', fn ($m) => str_starts_with($m, 'Unknown status "B-SECRET".') && substr_count($m, 'B-SECRET') === 1);

    expect($this->web->columns()->count())->toBe(4);
});

test('status_id in a request body is ignored', function () {
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'title' => 'Renamed', 'status_id' => $this->secret->id])->assertOk()->assertJsonPath('data.status', 'To Do');
    $this->postJson('/api/v1/projects/WEB/issues', ['title' => 'x', 'status_id' => $this->secret->id])->assertCreated()->assertJsonPath('data.status', 'To Do');
    $this->postJson('/api/v1/issues/WEB-1/move', ['status_id' => $this->secret->id])->assertOk()->assertJsonPath('data.status', 'To Do');

    expect(Issue::where('status_id', $this->secret->id)->exists())->toBeFalse();
});

test('a project Member and an agent cannot create a column or a status', function () {
    $audits = AuditLog::where('action', 'status.created')->count();
    $member = addMember($this->web, User::factory()->create(), ProjectRole::Member);

    apiAsPerson($member);
    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'QA'])->assertForbidden()
        ->assertJsonPath('message', 'Your role in WEB is member; only admins can do this.');

    apiAs($this->ann, AgentLevel::Delete);
    $this->postJson('/api/v1/projects/WEB/columns', ['name' => 'QA'])->assertForbidden()->assertJsonPath('message', ProjectPolicy::PEOPLE_ONLY);

    expect(Status::where('name', 'QA')->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'status.created')->count())->toBe($audits);
});

test("B's status can't be a merge target, renamed, recoloured, deleted or attached from A, and the errors don't reveal it", function () {
    $web = fn (string $method, string $path, array $data = []) => $this->actingAs(User::find($this->ann->id))->from(w('/settings/statuses'))->{$method}(w($path), $data);
    $before = Status::where('workspace_id', $this->secret->workspace_id)->get()->toArray();
    $colId = $this->web->columns()->where('name', 'In Review')->sole()->id;
    $inReview = Status::where('workspace_id', $this->a->id)->where('name', 'In Review')->sole();

    // As a merge target: same answer as a name that doesn't exist.
    $web('post', "/settings/statuses/{$inReview->id}/merge", ['into' => 'B-SECRET'])->assertSessionHasErrors('status');
    expect(session('errors')->first('status'))->toBe('Unknown status "B-SECRET". Statuses in this workspace: Done, In Progress, In Review, To Do.');
    // Its id on every status route and in a column detach.
    $web('patch', "/settings/statuses/{$this->secret->id}", ['name' => 'Hijacked', 'color' => 'coral'])->assertSessionHasErrors('status');
    $web('post', "/settings/statuses/{$this->secret->id}/merge", ['into' => 'Done'])->assertSessionHasErrors('status');
    $web('delete', "/settings/statuses/{$this->secret->id}")->assertSessionHasErrors('status');
    $web('delete', "/projects/WEB/columns/{$colId}/statuses/{$this->secret->id}")->assertSessionHasErrors('status');
    $web('post', "/projects/WEB/columns/{$colId}/statuses", ['status' => 'B-SECRET'])->assertSessionHasErrors('status');
    // A new status here with B's name is its own status: names are unique per workspace, not across them.
    $web('post', "/projects/WEB/columns/{$colId}/statuses", ['name' => 'B-SECRET', 'category' => 'done'])->assertSessionHasNoErrors();

    expect(Status::where('workspace_id', $this->secret->workspace_id)->get()->toArray())->toBe($before)
        ->and(Status::where('workspace_id', $this->a->id)->where('name', 'B-SECRET')->exists())->toBeTrue();
});

test('Review Focus 5: a merge never leaves an issue on a missing status or a project with one status in two columns', function () {
    $issue = Issue::sole();
    $review = app(StatusService::class)->create($this->a, $this->ann, 'Review', StatusCategory::InProgress);
    $this->web->columns()->where('name', 'In Review')->sole()->statuses()->attach($review->id, ['position' => 1]);
    $this->actingAs(User::find($this->ann->id))->patch(w('/issues/WEB-1'), ['version' => 1, 'status' => 'Review'])->assertSessionHasNoErrors();

    $this->actingAs(User::find($this->ann->id))->post(w("/settings/statuses/{$review->id}/merge"), ['into' => 'In Review'])->assertSessionHasNoErrors();

    expect($issue->fresh()->status->name)->toBe('In Review')
        ->and(DB::table('column_statuses')->join('columns', 'columns.id', '=', 'column_statuses.column_id')->where('columns.project_id', $this->web->id)
            ->select('column_statuses.status_id')->groupBy('column_statuses.status_id')->havingRaw('count(*) > 1')->count())->toBe(0);
});
