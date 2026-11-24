<?php

use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LinkService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Owner review of PR 38: two requests at the same moment on a second connection ("locker"). MySQL keeps one original
 * per Duplicate (a unique index on a generated column), and blocks/precedes links are added one at a time per
 * workspace (a named lock), so a loop can't slip in between the check and the insert.
 */

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = makeWorkspace($this->owner, 'acme');
    $web = makeProject($this->owner, 'WEB');
    foreach (range(1, 3) as $n) {
        makeIssue($web, $this->owner, ['title' => "Issue {$n}"]);
    }
    $this->issue = fn (int $n) => Issue::where('number', $n)->with('project')->sole();
    $this->links = app(LinkService::class);
    config(['database.connections.locker' => config('database.connections.'.config('database.default'))]);
    $this->locker = DB::connection('locker');
});

afterEach(function () {
    // Always release, or truncating after a failure would wait on the locker.
    $this->locker->transactionLevel() && $this->locker->rollBack();
    $this->locker->select('SELECT RELEASE_ALL_LOCKS()');
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
});

test('a duplicates link racing another one for the same issue: they can\'t both commit, and the last writer replaces', function () {
    $this->locker->beginTransaction();
    $this->locker->table('issue_links')->insert(['source_issue_id' => ($this->issue)(1)->id, 'target_issue_id' => ($this->issue)(2)->id, 'type' => 'duplicates', 'via' => 'web']);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    expect(fn () => $this->links->link(($this->issue)(1), 'duplicates', ($this->issue)(3), $this->owner))->toThrow(QueryException::class, 'Lock wait timeout');
    $this->locker->commit();

    $this->links->link(($this->issue)(1), 'duplicates', ($this->issue)(3), $this->owner);
    expect(IssueLink::where('source_issue_id', ($this->issue)(1)->id)->where('type', 'duplicates')->pluck('target_issue_id')->all())->toBe([($this->issue)(3)->id]);
});

test('a duplicates link committed between our read and our insert: we retry once and replace it', function () {
    // Under REPEATABLE READ our locking read's gap lock makes the other insert wait; READ COMMITTED has no gap locks,
    // so this is where the index and the retry hold the rule on their own.
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $done = false;
    DB::listen(function ($query) use (&$done) {
        // Right after we read the issue's current duplicates links, another request commits one.
        if (! $done && $query->connectionName !== 'locker' && str_contains($query->sql, 'from `issue_links` where `source_issue_id` = ? and `type` = ?')) {
            $done = true;
            $this->locker->table('issue_links')->insert(['source_issue_id' => ($this->issue)(1)->id, 'target_issue_id' => ($this->issue)(2)->id, 'type' => 'duplicates', 'via' => 'web']);
        }
    });

    $this->links->link(($this->issue)(1), 'duplicates', ($this->issue)(3), $this->owner);

    expect($done)->toBeTrue()
        ->and(IssueLink::where('source_issue_id', ($this->issue)(1)->id)->where('type', 'duplicates')->pluck('target_issue_id')->all())->toBe([($this->issue)(3)->id]);
});

test('blocks links wait for the workspace\'s link lock; a timeout says try again, and after it the loop is refused', function () {
    $this->links->link(($this->issue)(1), 'blocks', ($this->issue)(2), $this->owner);
    $this->links->link(($this->issue)(2), 'blocks', ($this->issue)(3), $this->owner);
    config(['pm.link_lock_timeout' => 1]);
    expect((int) $this->locker->scalar('SELECT GET_LOCK(?, 0)', ["pm:links:{$this->workspace->id}"]))->toBe(1);

    expect(fn () => $this->links->link(($this->issue)(3), 'blocks', ($this->issue)(1), $this->owner))
        ->toThrow(fn (ValidationException $e) => expect($e->status)->toBe(409)->and($e->errors())->toBe(['type' => ['Links are being changed in this workspace; try again.']]));
    expect(IssueLink::count())->toBe(2);

    $this->locker->select('SELECT RELEASE_LOCK(?)', ["pm:links:{$this->workspace->id}"]);
    expect(fn () => $this->links->link(($this->issue)(3), 'blocks', ($this->issue)(1), $this->owner))
        ->toThrow(ValidationException::class, 'That would make a loop: WEB-1 → WEB-2 → WEB-3 → WEB-1.');
    // The lock is ours to take again: it was released after each call.
    expect((int) $this->locker->scalar('SELECT GET_LOCK(?, 0)', ["pm:links:{$this->workspace->id}"]))->toBe(1);
});

test('the loop check reads links committed after our transaction began', function () {
    $this->links->link(($this->issue)(1), 'blocks', ($this->issue)(2), $this->owner);

    // REPEATABLE READ: an older snapshot would miss 2 blocks 3 and let 3 block 1.
    expect(fn () => DB::transaction(function () {
        IssueLink::count();
        $this->locker->table('issue_links')->insert(['source_issue_id' => ($this->issue)(2)->id, 'target_issue_id' => ($this->issue)(3)->id, 'type' => 'blocks', 'via' => 'web']);
        $this->links->link(($this->issue)(3), 'blocks', ($this->issue)(1), $this->owner);
    }))->toThrow(ValidationException::class, 'That would make a loop: WEB-1 → WEB-2 → WEB-3 → WEB-1.');
});
