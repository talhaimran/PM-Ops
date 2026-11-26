<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Services\WorkspaceMemberService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review focus 2: two Owners demoting or removing each other at once. A second connection holds the workspace row
 * the way a concurrent request would; the change must wait for it (here: time out) instead of checking stale roles.
 */
test('role changes and removals wait on the workspace row lock', function (string $action) {
    $owner = User::factory()->create();
    $ws = makeWorkspace($owner, 'acme');
    $other = User::factory()->create();
    $ws->addMember($other, WorkspaceRole::Owner);
    $service = app(WorkspaceMemberService::class);
    $run = fn () => $action === 'changeRole'
        ? $service->changeRole($ws, $owner, $other, WorkspaceRole::Member)
        : $service->remove($ws, $owner, $other);

    config(['database.connections.locker' => config('database.connections.'.config('database.default'))]);
    $locker = DB::connection('locker');
    $locker->beginTransaction();
    $locker->table('workspaces')->where('id', $ws->id)->lockForUpdate()->first();
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        expect($run)->toThrow(QueryException::class, 'Lock wait timeout');
        expect($other->workspaceRole($ws))->toBe(WorkspaceRole::Owner);
    } finally {
        // Always release, or truncating after a failure would wait on the lock forever.
        $locker->rollBack();
    }
    $run();
    expect($ws->members()->wherePivot('role', 'owner')->count())->toBe(1);
})->with(['changeRole', 'remove']);

/** PR 50 review: two Owners deleting their accounts at once must not leave the workspace without one. */
test('account deletion waits on the workspace row lock and re-checks the last Owner under it', function () {
    $owner = User::factory()->create();
    $ws = makeWorkspace($owner, 'acme');
    $other = User::factory()->create();
    $ws->addMember($other, WorkspaceRole::Owner);
    $service = app(WorkspaceMemberService::class);

    config(['database.connections.locker' => config('database.connections.'.config('database.default'))]);
    $locker = DB::connection('locker');
    $locker->beginTransaction();
    $locker->table('workspaces')->where('id', $ws->id)->lockForUpdate()->first();
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        expect(fn () => $service->deleteAccount($other))->toThrow(QueryException::class, 'Lock wait timeout');
        expect(User::find($other->id))->not->toBeNull();
    } finally {
        $locker->rollBack();
    }

    // Both passed the page's check before either deleted: the second must now be refused under the lock.
    $service->deleteAccount($other);
    expect(fn () => $service->deleteAccount($owner))->toThrow(ValidationException::class, 'last Owner');
    expect(User::find($owner->id))->not->toBeNull()
        ->and($ws->members()->wherePivot('role', 'owner')->count())->toBe(1);
});
