<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Models\AuditLog;
use App\Models\Issue;
use App\Models\Status;
use App\Models\User;
use App\Policies\ProjectPolicy;

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
