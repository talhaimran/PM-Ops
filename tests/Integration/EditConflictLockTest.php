<?php

use App\Exceptions\EditConflict;
use App\Models\Issue;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Review Focus 3 (step 1, Task 4): two saves at the same moment with the same version. A second connection is the
 * other save, held inside its transaction after its write; ours must wait for it (here: time out) rather than check a
 * stale version, and once it commits ours gets the conflict: exactly one wins, no lost update.
 */
test('two saves with the same version: one commits, the other gets EditConflict', function () {
    $owner = User::factory()->create();
    makeWorkspace($owner, 'acme');
    $web = makeProject($owner, 'WEB');
    makeIssue($web, $owner, ['title' => 'Original']);
    $issues = app(IssueService::class);
    // Our copy is read before the other save commits, like a request that loaded the issue first.
    $ours = $issues->resolve($owner, 'WEB-1');
    $save = fn () => $issues->update($ours, $owner, ['title' => 'Ours', 'version' => 1]);

    config(['database.connections.locker' => config('database.connections.'.config('database.default'))]);
    $locker = DB::connection('locker');
    $locker->beginTransaction();
    // The other save, as IssueService::update makes it: lock the row, check version 1, write and bump.
    expect($locker->table('issues')->where('id', $ours->id)->lockForUpdate()->value('version'))->toBe(1);
    $locker->table('issues')->where('id', $ours->id)->update(['title' => 'Theirs', 'version' => 2]);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        expect($save)->toThrow(QueryException::class, 'Lock wait timeout');
        $locker->commit();
    } finally {
        // Always release, or truncating after a failure would wait on the lock forever.
        $locker->transactionLevel() && $locker->rollBack();
    }

    expect($save)->toThrow(EditConflict::class, 'WEB-1 changed since version 1. Read it again and resend.');
    expect(Issue::sole())->title->toBe('Theirs')->version->toBe(2);
});

test('the issue row is locked before its version is read', function () {
    $owner = User::factory()->create();
    makeWorkspace($owner, 'acme');
    $web = makeProject($owner, 'WEB');
    makeIssue($web, $owner);
    $issues = app(IssueService::class);
    $issue = $issues->resolve($owner, 'WEB-1');

    DB::enableQueryLog();
    $issues->update($issue, $owner, ['title' => 'x', 'version' => 1]);
    $queries = collect(DB::getQueryLog())->pluck('query');

    $lock = $queries->search(fn ($q) => str_starts_with($q, 'select * from `issues`') && str_ends_with($q, 'for update'));
    $write = $queries->search(fn ($q) => str_starts_with($q, 'update `issues`'));
    expect($lock)->toBeInt()->and($write)->toBeInt()->and($lock)->toBeLessThan($write);
});
