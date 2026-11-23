<?php

use App\Exceptions\EditConflict;
use App\Models\Issue;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

test('the version is checked on the current row, not on what the transaction read before another save committed', function () {
    $owner = User::factory()->create();
    makeWorkspace($owner, 'acme');
    $web = makeProject($owner, 'WEB');
    makeIssue($web, $owner, ['title' => 'Original']);
    $issues = app(IssueService::class);
    $ours = $issues->resolve($owner, 'WEB-1');
    config(['database.connections.locker' => config('database.connections.'.config('database.default'))]);

    // REPEATABLE READ: our transaction reads first, then the other save commits. A plain read of the version would
    // still see 1 and save over theirs; the locking read sees 2 and refuses.
    expect(fn () => DB::transaction(function () use ($issues, $ours, $owner) {
        Issue::find($ours->id);
        DB::connection('locker')->table('issues')->where('id', $ours->id)->update(['title' => 'Theirs', 'version' => 2]);
        $issues->update($ours, $owner, ['title' => 'Ours', 'version' => 1]);
    }))->toThrow(EditConflict::class, 'WEB-1 changed since version 1.');

    expect(Issue::sole())->title->toBe('Theirs')->version->toBe(2);
});

/** Runs $commit on a second connection right after the first row lock the bulk update takes (its project lock). */
function afterFirstLock(callable $commit): void
{
    config(['database.connections.locker' => config('database.connections.'.config('database.default'))]);
    $done = false;
    DB::listen(function ($query) use (&$done, $commit) {
        if (! $done && $query->connectionName !== 'locker' && str_ends_with($query->sql, 'for update')) {
            $done = true;
            $commit(DB::connection('locker'));
        }
    });
}

test('S1: a bulk 409 reports the version and changes committed while it waited, not a stale read', function () {
    $owner = User::factory()->create();
    makeWorkspace($owner, 'acme');
    $web = makeProject($owner, 'WEB');
    $issue = makeIssue($web, $owner, ['title' => 'Original']);
    afterFirstLock(function ($locker) use ($issue, $owner) {
        $locker->table('issues')->where('id', $issue->id)->update(['title' => 'Theirs', 'version' => 2]);
        $locker->table('activities')->insert(['issue_id' => $issue->id, 'user_id' => $owner->id, 'action' => 'updated', 'via' => 'web', 'issue_version' => 2,
            'changes' => json_encode(['title' => ['from' => 'Original', 'to' => 'Theirs']]), 'created_at' => now()]);
    });

    try {
        app(IssueService::class)->updateMany($owner, [['key' => 'WEB-1', 'version' => 1, 'title' => 'Ours']]);
        $this->fail('Expected EditConflict');
    } catch (EditConflict $e) {
        expect($e->payload['conflicts'][0])->toMatchArray(['key' => 'WEB-1', 'current_version' => 2])
            ->and($e->payload['conflicts'][0]['changes'][0])->toMatchArray(['field' => 'title', 'to' => 'Theirs']);
    }
    expect(Issue::sole()->title)->toBe('Theirs');
});

test('S1: an issue deleted while the bulk update waited is the usual not-found error, not a 500', function () {
    $owner = User::factory()->create();
    makeWorkspace($owner, 'acme');
    $web = makeProject($owner, 'WEB');
    $issue = makeIssue($web, $owner);
    afterFirstLock(fn ($locker) => $locker->table('issues')->where('id', $issue->id)->update(['deleted_at' => now()]));

    expect(fn () => app(IssueService::class)->updateMany($owner, [['key' => 'WEB-1', 'version' => 1, 'title' => 'x']]))
        ->toThrow(ValidationException::class, 'Issue WEB-1 was not found.');
});
