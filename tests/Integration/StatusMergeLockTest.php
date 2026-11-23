<?php

use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\Status;
use App\Models\User;
use App\Services\ColumnService;
use App\Services\StatusService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review Focus 5 (step 1, PR 5): a merge racing an issue move or a column attach. A second connection holds the rows the
 * way a concurrent request would; the merge waits for it (here: times out), then works on what committed.
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    makeWorkspace($this->owner, 'acme');
    $this->web = makeProject($this->owner, 'WEB');
    $this->statuses = app(StatusService::class);
    $this->review = $this->statuses->create($this->web->workspace, $this->owner, 'Review', StatusCategory::InProgress);
    $this->web->columns()->where('name', 'In Review')->sole()->statuses()->attach($this->review->id, ['position' => 1]);
    makeIssue($this->web, $this->owner);

    config(['database.connections.locker' => config('database.connections.'.config('database.default'))]);
    $this->locker = DB::connection('locker');
    $this->locker->beginTransaction();
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
});

afterEach(fn () => $this->locker->transactionLevel() && $this->locker->rollBack());

test('an issue moved into the source while the merge runs ends on the surviving status', function () {
    // A mover holds the project row, as IssueService::update does, and is about to put the issue on Review.
    $this->locker->table('projects')->where('id', $this->web->id)->lockForUpdate()->first();
    $merge = fn () => $this->statuses->merge($this->review, 'In Review', $this->owner);

    expect($merge)->toThrow(QueryException::class, 'Lock wait timeout');
    $this->locker->table('issues')->update(['status_id' => $this->review->id]);
    $this->locker->commit();

    expect($merge())->toBe(1)
        ->and(Issue::sole()->status->name)->toBe('In Review')
        ->and(Status::find($this->review->id))->toBeNull();
});

test('a column attach waits for a merge, and a merge waits for an attach, so no project gets one status in two columns', function () {
    $qa = $this->web->columns()->create(['name' => 'QA', 'position' => 4]);
    // The merge holds the workspace row: an attach of the source waits, then finds it gone.
    $this->locker->table('workspaces')->where('id', $this->web->workspace_id)->lockForUpdate()->first();
    $attach = fn () => app(ColumnService::class)->addStatus($qa->setRelation('project', $this->web), $this->owner, ['status' => 'Review']);

    expect($attach)->toThrow(QueryException::class, 'Lock wait timeout');
    $this->locker->table('column_statuses')->where('status_id', $this->review->id)->delete();
    $this->locker->table('statuses')->where('id', $this->review->id)->delete();
    $this->locker->commit();
    expect($attach)->toThrow(ValidationException::class, 'Unknown status "Review"');

    // The reverse: the attach holds the workspace row and has put the source in a second column; the merge then refuses.
    $other = $this->statuses->create($this->web->workspace, $this->owner, 'Review 2', StatusCategory::InProgress);
    $this->web->columns()->where('name', 'In Review')->sole()->statuses()->attach($other->id, ['position' => 1]);
    $this->locker->beginTransaction();
    $this->locker->table('workspaces')->where('id', $this->web->workspace_id)->lockForUpdate()->first();
    $merge = fn () => $this->statuses->merge($other, 'In Review', $this->owner);
    expect($merge)->toThrow(QueryException::class, 'Lock wait timeout');
    $this->locker->table('column_statuses')->where('status_id', $other->id)->delete();
    $this->locker->table('column_statuses')->insert(['column_id' => $qa->id, 'status_id' => $other->id, 'position' => 0]);
    $this->locker->commit();

    expect($merge)->toThrow(ValidationException::class, 'Review 2 and In Review are in different columns of WEB');
    expect(Status::find($other->id))->not->toBeNull();
});
