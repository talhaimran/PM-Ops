<?php

use App\Models\Issue;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review (step 1, PR 1): a status change racing a column delete must not leave an issue on a status no column of its
 * project shows. A second connection holds the project row the way ColumnService::delete does; the status change
 * waits for it (here: times out), then resolves against the committed columns.
 */
test('a status change waits on the project row lock, then resolves against the committed columns', function (string $action) {
    $owner = User::factory()->create();
    makeWorkspace($owner, 'acme');
    $web = makeProject($owner, 'WEB');
    makeIssue($web, $owner);
    $issues = app(IssueService::class);
    $run = fn () => $action === 'update'
        ? $issues->update($issues->resolve($owner, 'WEB-1'), $owner, ['status' => 'In Review'])
        : $issues->create($web->fresh(), $owner, ['title' => 'x', 'status' => 'In Review']);

    config(['database.connections.locker' => config('database.connections.'.config('database.default'))]);
    $locker = DB::connection('locker');
    $locker->beginTransaction();
    $locker->table('projects')->where('id', $web->id)->lockForUpdate()->first();
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        expect($run)->toThrow(QueryException::class, 'Lock wait timeout');
        // The other request deletes the empty column while it holds the project.
        $locker->table('columns')->where('project_id', $web->id)->where('name', 'In Review')->delete();
        $locker->commit();
    } finally {
        // Always release, or truncating after a failure would wait on the lock forever.
        $locker->transactionLevel() && $locker->rollBack();
    }

    expect($run)->toThrow(ValidationException::class, 'Unknown status "In Review". Valid statuses for WEB: To Do, In Progress, Done.');
    expect(Issue::count())->toBe(1)->and(Issue::sole()->status->name)->toBe('To Do');
})->with(['update', 'create']);

test('the project row is locked before the status is resolved', function (string $action) {
    $owner = User::factory()->create();
    makeWorkspace($owner, 'acme');
    $web = makeProject($owner, 'WEB');
    makeIssue($web, $owner);
    $issues = app(IssueService::class);
    $issue = $issues->resolve($owner, 'WEB-1');

    DB::enableQueryLog();
    $action === 'update'
        ? $issues->update($issue, $owner, ['status' => 'In Review'])
        : $issues->create($web, $owner, ['title' => 'x', 'status' => 'In Review']);
    $queries = collect(DB::getQueryLog())->pluck('query');

    $lock = $queries->search(fn ($q) => str_starts_with($q, 'select * from `projects`') && str_ends_with($q, 'for update'));
    $resolve = $queries->search(fn ($q) => str_contains($q, 'from `statuses` inner join `column_statuses`'));
    expect($lock)->toBeInt()->and($resolve)->toBeInt()->and($lock)->toBeLessThan($resolve);
})->with(['update', 'create']);

test('a bulk update locks all its projects before it changes any issue, like move and approve', function () {
    $owner = User::factory()->create();
    makeWorkspace($owner, 'acme');
    $web = makeProject($owner, 'WEB');
    makeIssue($web, $owner);
    makeIssue($web, $owner);

    config(['database.connections.locker' => config('database.connections.'.config('database.default'))]);
    $locker = DB::connection('locker');
    $locker->beginTransaction();
    $locker->table('projects')->where('id', $web->id)->lockForUpdate()->first();
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::enableQueryLog();

    try {
        expect(fn () => app(IssueService::class)->updateMany($owner, [['key' => 'WEB-1', 'version' => 1, 'title' => 'x'], ['key' => 'WEB-2', 'version' => 1, 'status' => 'In Review']]))
            // Inside updateMany's outer transaction Laravel reports the timeout as a DeadlockException.
            ->toThrow(PDOException::class, 'Lock wait timeout');
    } finally {
        $locker->rollBack();
    }

    // Holding WEB-1's row while waiting for the project is the deadlock with approve (project, then issue).
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_starts_with($q, 'update `issues`'))->all())->toBe([]);
});
