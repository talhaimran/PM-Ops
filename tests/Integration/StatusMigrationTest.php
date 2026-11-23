<?php

use App\Models\Issue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// Runs real migrations (DDL commits in MySQL), so it lives with the truncating Integration tests, like AgentMigrationTest.

function migrateToBeforeStepOne(): void
{
    $paths = collect(File::files(database_path('migrations')))
        ->map(fn ($f) => $f->getPathname())
        ->filter(fn ($p) => basename($p) < '2026_11_24')
        ->sort()->values()->all();

    Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]);
}

/**
 * Review Focus 1's odd installs, in the step A shape: WEB and OPS with the default columns, API with Done first,
 * SOLO with one column, EMPTY with no issues; soft-deleted issues; approval requests to live and deleted columns.
 */
function stepAInstall(): array
{
    $now = now();
    $user = DB::table('users')->insertGetId(['name' => 'demo', 'email' => 'demo@example.com', 'password' => bcrypt('password'), 'created_at' => $now, 'updated_at' => $now]);
    $ws = DB::table('workspaces')->insertGetId(['name' => 'Acme', 'slug' => 'acme', 'created_at' => $now, 'updated_at' => $now]);
    $agent = DB::table('agents')->insertGetId(['workspace_id' => $ws, 'owner_user_id' => $user, 'name' => 'bot', 'permission_level' => 'write', 'created_at' => $now, 'updated_at' => $now]);
    $columns = [];
    $issues = [];
    $project = function (string $key, array $names) use ($ws, $now, &$columns, &$issues) {
        $id = DB::table('projects')->insertGetId(['workspace_id' => $ws, 'key' => $key, 'name' => $key, 'created_at' => $now, 'updated_at' => $now]);
        foreach ($names as $position => $name) {
            $columns[$key][$name] = DB::table('columns')->insertGetId(['project_id' => $id, 'name' => $name, 'position' => $position, 'created_at' => $now, 'updated_at' => $now]);
        }
        if ($key !== 'EMPTY') {
            foreach (array_values($columns[$key]) as $n => $column) {
                // Every other issue is soft-deleted: it must keep its column too.
                $issues[$key][$n + 1] = DB::table('issues')->insertGetId([
                    'project_id' => $id, 'number' => $n + 1, 'title' => "{$key} ".($n + 1), 'type' => 'task', 'priority' => 'medium',
                    'column_id' => $column, 'rank' => ($n + 1) * 1024, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $n % 2 ? $now : null,
                ]);
            }
        }

        return $id;
    };
    $project('WEB', ['To Do', 'In Progress', 'In Review', 'Done']);
    $project('OPS', ['To Do', 'In Progress', 'Done']);
    $project('API', ['Done', 'Doing', 'Shipped']);
    $project('SOLO', ['Done']);
    $project('EMPTY', ['To Do', 'Done']);

    $request = fn (int $issue, array $action, string $status = 'pending') => DB::table('approval_requests')->insertGetId([
        'workspace_id' => $ws, 'issue_id' => $issue, 'agent_id' => $agent, 'owner_user_id' => $user, 'action' => json_encode($action),
        'status' => $status, 'expires_at' => $now->copy()->addDays(7), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $live = $request($issues['WEB'][3], ['to_column_id' => $columns['WEB']['Done'], 'to_column' => 'Done', 'from_column_id' => $columns['WEB']['In Review'], 'reason' => 'PR merged']);
    $gone = $request($issues['OPS'][1], ['to_column_id' => 999999, 'to_column' => 'Shipped', 'from_column_id' => $columns['OPS']['To Do'], 'reason' => null]);
    $decided = $request($issues['OPS'][1], ['to_column_id' => 999998, 'to_column' => 'Old', 'from_column_id' => 999997, 'reason' => null], 'rejected');

    return compact('ws', 'columns', 'issues', 'live', 'gone', 'decided');
}

test('Review Focus 1: columns become library statuses and every issue keeps its column', function () {
    migrateToBeforeStepOne();
    $i = stepAInstall();
    $before = DB::table('issues')->orderBy('id')->pluck('column_id', 'id')->all();

    Artisan::call('migrate', ['--force' => true]);

    $status = fn (string $name) => DB::table('statuses')->where('workspace_id', $i['ws'])->where('name', $name)->first();
    expect($status('To Do')->category)->toBe('to_do')
        ->and($status('In Progress')->category)->toBe('in_progress')
        ->and($status('In Review')->category)->toBe('in_progress')
        ->and($status('Done')->category)->toBe('done')
        // API's first column is Done: to_do clashes with the library's Done, so it gets its own name.
        ->and($status('Done (API)')->category)->toBe('to_do')
        ->and($status('Doing')->category)->toBe('in_progress')
        ->and($status('Shipped')->category)->toBe('done')
        // 1-R1: a one-column project's column is its last, so done.
        ->and(DB::table('statuses')->where('workspace_id', $i['ws'])->count())->toBe(7);

    // OPS and SOLO reuse WEB's rows.
    $mapped = fn (string $key, string $column) => DB::table('column_statuses')->where('column_id', $i['columns'][$key][$column])->pluck('status_id')->all();
    expect($mapped('OPS', 'Done'))->toBe([$status('Done')->id])
        ->and($mapped('SOLO', 'Done'))->toBe([$status('Done')->id])
        ->and($mapped('API', 'Done'))->toBe([$status('Done (API)')->id])
        ->and($mapped('EMPTY', 'To Do'))->toBe([$status('To Do')->id]);

    // Every issue, soft-deleted ones included, has a status that maps to its old column.
    $after = DB::table('issues')->join('column_statuses', 'column_statuses.status_id', '=', 'issues.status_id')
        ->join('columns', fn ($j) => $j->on('columns.id', '=', 'column_statuses.column_id')->on('columns.project_id', '=', 'issues.project_id'))
        ->orderBy('issues.id')->pluck('columns.id', 'issues.id')->all();
    expect($after)->toBe($before)
        ->and(Schema::hasColumn('issues', 'column_id'))->toBeFalse()
        ->and(Issue::withTrashed()->whereNull('status_id')->exists())->toBeFalse();
});

test('1-R6: approval requests are converted to statuses; a pending one whose column is gone becomes stale', function () {
    migrateToBeforeStepOne();
    $i = stepAInstall();

    Artisan::call('migrate', ['--force' => true]);

    $statusId = fn (string $name) => DB::table('statuses')->where('name', $name)->value('id');
    $row = fn (int $id) => DB::table('approval_requests')->find($id);
    expect(json_decode($row($i['live'])->action, true))->toEqual(['to_status_id' => $statusId('Done'), 'to_status' => 'Done', 'from_status_id' => $statusId('In Review'), 'reason' => 'PR merged', 'issue_version' => 1])
        ->and($row($i['live'])->status)->toBe('pending')
        ->and(json_decode($row($i['gone'])->action, true))->toEqual(['to_status_id' => null, 'to_status' => 'Shipped', 'from_status_id' => $statusId('To Do'), 'reason' => null])
        ->and($row($i['gone'])->status)->toBe('stale')
        ->and($row($i['gone'])->note)->toBe('The column Shipped was deleted.')
        // Every row gets the new keys, so readers know one shape; a decided one keeps its status.
        ->and(json_decode($row($i['decided'])->action, true))->toEqual(['to_status_id' => null, 'to_status' => 'Old', 'from_status_id' => null, 'reason' => null])
        ->and($row($i['decided'])->status)->toBe('rejected');
});

test('rolling back restores issues.column_id with the old values and the old approval shape', function () {
    migrateToBeforeStepOne();
    $i = stepAInstall();
    $before = DB::table('issues')->orderBy('id')->pluck('column_id', 'id')->all();
    Artisan::call('migrate', ['--force' => true]);

    expect(Artisan::call('migrate:rollback', ['--force' => true]))->toBe(0);

    expect(Schema::hasTable('statuses'))->toBeFalse()
        ->and(Schema::hasTable('column_statuses'))->toBeFalse()
        ->and(Schema::hasColumn('issues', 'status_id'))->toBeFalse()
        ->and(DB::table('issues')->orderBy('id')->pluck('column_id', 'id')->all())->toBe($before)
        ->and(json_decode(DB::table('approval_requests')->find($i['live'])->action, true))
        ->toEqual(['to_column_id' => $i['columns']['WEB']['Done'], 'to_column' => 'Done', 'from_column_id' => $i['columns']['WEB']['In Review'], 'reason' => 'PR merged']);

    Artisan::call('migrate', ['--force' => true]);
});
