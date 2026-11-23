<?php

use App\Enums\AgentLevel;
use App\Models\ApprovalRequest;
use App\Models\Issue;
use App\Models\User;

// duplicate_of resolves only through what the actor can open, and an issue they can't open looks like one that doesn't exist.

test('duplicate_of gives one error for unknown, inaccessible and other-workspace keys', function () {
    $ann = User::factory()->create();
    $web = makeProject($ann, 'WEB');
    makeIssue($web, $ann, ['title' => 'mine']);

    // Same workspace, a project Ann is not a member of: a plain Member sees only their own projects.
    $bob = User::factory()->create();
    $ops = makeProject($bob, 'OPS');
    makeIssue($ops, $bob, ['title' => 'B-SECRET ops']);
    asWorkspaceMember($ann);

    // Another workspace.
    $zed = User::factory()->create();
    makeWorkspace($zed, 'elsewhere');
    makeIssue(makeProject($zed, 'ZED'), $zed, ['title' => 'B-SECRET zed']);

    useWorkspace($web->workspace, $ann);
    apiAsPerson($ann);
    $messages = collect(['WEB-99', 'OPS-1', 'ZED-1', 'nonsense'])->map(fn ($key) => str_replace($key, 'KEY', $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => $key])
        ->assertUnprocessable()->json('errors.duplicate_of.0')));

    expect($messages->unique())->toHaveCount(1)
        ->and($messages->first())->not->toContain('OPS')->not->toContain('ZED')
        ->and(Issue::where('title', 'mine')->sole()->status->name)->toBe('To Do');
});

test("another agent's repeat returns the pending request and cannot rewrite its resolution", function () {
    $owner = User::factory()->create();
    $web = makeProject($owner, 'WEB');
    makeIssue($web, $owner, ['title' => 'one', 'status' => 'In Review']);
    makeIssue($web, $owner, ['title' => 'two']);
    [, $a] = makeAgent($owner, AgentLevel::Write);
    [, $b] = makeAgent($owner, AgentLevel::Write);

    $id = asToken($a)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-2'])->assertStatus(202)->json('approval_request.id');
    foreach ([['resolution' => 'wont_do'], []] as $extra) {
        asToken($b)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done', ...$extra])->assertStatus(202)->assertJsonPath('approval_request.id', $id);
    }

    expect(ApprovalRequest::sole()->action)->toMatchArray(['resolution' => 'duplicate', 'duplicate_of' => 'WEB-2']);
});
