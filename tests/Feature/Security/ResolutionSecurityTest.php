<?php

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
    $messages = collect(['WEB-99', 'OPS-1', 'ZED-1', 'nonsense'])->map(fn ($key) => str_replace($key, 'KEY', $this->patchJson('/api/v1/issues/WEB-1', ['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => $key])
        ->assertUnprocessable()->json('errors.duplicate_of.0')));

    expect($messages->unique())->toHaveCount(1)
        ->and($messages->first())->not->toContain('OPS')->not->toContain('ZED')
        ->and(Issue::where('title', 'mine')->sole()->status->name)->toBe('To Do');
});
