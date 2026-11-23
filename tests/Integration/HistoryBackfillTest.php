<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// Real migrations (DDL commits in MySQL), so it sits with the truncating Integration tests.

test('the history migration rebuilds status, sprint and estimate history from the activity log and counts what it skips', function () {
    $paths = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getPathname())
        ->filter(fn ($p) => basename($p) < '2026_11_26')->sort()->values()->all();
    Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]);

    $this->freezeTime();
    $base = now()->startOfSecond();
    $t = fn (int $day) => $base->copy()->subDays(30 - $day)->toDateTimeString();
    $user = DB::table('users')->insertGetId(['name' => 'demo', 'email' => 'demo@example.com', 'password' => 'x', 'created_at' => $t(0), 'updated_at' => $t(0)]);
    $ws = DB::table('workspaces')->insertGetId(['name' => 'Acme', 'slug' => 'acme', 'created_at' => $t(0), 'updated_at' => $t(0)]);
    $agent = DB::table('agents')->insertGetId(['workspace_id' => $ws, 'owner_user_id' => $user, 'name' => 'bot', 'permission_level' => 'write', 'created_at' => $t(0), 'updated_at' => $t(0)]);
    $project = DB::table('projects')->insertGetId(['workspace_id' => $ws, 'key' => 'WEB', 'name' => 'Web', 'created_at' => $t(0), 'updated_at' => $t(0)]);
    $statuses = [];
    foreach (['To Do' => 'to_do', 'In Progress' => 'in_progress', 'Done' => 'done'] as $i => $category) {
        $name = array_search($category, ['To Do' => 'to_do', 'In Progress' => 'in_progress', 'Done' => 'done']);
        $statuses[$name] = DB::table('statuses')->insertGetId(['workspace_id' => $ws, 'name' => $name, 'category' => $category, 'created_at' => $t(0), 'updated_at' => $t(0)]);
        $column = DB::table('columns')->insertGetId(['project_id' => $project, 'name' => $name, 'position' => count($statuses), 'created_at' => $t(0), 'updated_at' => $t(0)]);
        DB::table('column_statuses')->insert(['column_id' => $column, 'status_id' => $statuses[$name], 'position' => 0]);
    }
    $sprint = fn (string $name) => DB::table('sprints')->insertGetId(['project_id' => $project, 'name' => $name, 'created_at' => $t(0), 'updated_at' => $t(0)]);
    [$s1, $s2] = [$sprint('S1'), $sprint('S2')];
    $issue = fn (int $n, string $status, array $extra = []) => DB::table('issues')->insertGetId([
        'project_id' => $project, 'number' => $n, 'title' => "i{$n}", 'type' => 'task', 'priority' => 'medium', 'status_id' => $statuses[$status],
        'rank' => $n * 1024, 'reporter_id' => $user, 'created_at' => $t(1), 'updated_at' => $t(9), ...$extra,
    ]);
    $a = $issue(1, 'Done', ['sprint_id' => $s2, 'story_points' => 8]);
    $b = $issue(2, 'In Progress', ['sprint_id' => $s1, 'story_points' => 3]);
    $c = $issue(3, 'To Do');
    $e = $issue(5, 'Done');
    $d = $issue(4, 'Done', ['deleted_at' => $t(9)]);
    $activity = fn (int $id, int $day, array $changes, ?int $agentId = null, string $via = 'api') => DB::table('activities')->insert([
        'issue_id' => $id, 'user_id' => $user, 'agent_id' => $agentId, 'action' => 'updated', 'changes' => json_encode($changes), 'via' => $via, 'created_at' => $t($day),
    ]);
    $activity($a, 2, ['status' => ['from' => 'To Do', 'to' => 'In Progress']], $agent);
    $activity($a, 3, ['story_points' => ['from' => null, 'to' => 5]]);
    $activity($a, 4, ['sprint' => ['from' => null, 'to' => 'S1']]);
    $activity($a, 5, ['status' => ['from' => 'In Progress', 'to' => 'Done']], via: 'web');
    $activity($a, 6, ['sprint' => ['from' => 'S1', 'to' => 'S2']]);
    $activity($a, 7, ['story_points' => ['from' => 5, 'to' => 8]]);
    $activity($d, 3, ['status' => ['from' => 'To Do', 'to' => 'Done']]);
    $activity($e, 3, ['status' => ['from' => 'Ghost', 'to' => 'Done']]);
    $activity($c, 2, ['status' => ['from' => 'To Do', 'to' => 'Ghost']]);

    ob_start();
    Artisan::call('migrate', ['--force' => true]);
    $output = ob_get_clean();

    $rows = fn (string $table, int $id, array $cols) => DB::table($table)->where('issue_id', $id)->orderBy('id')->get()->map(fn ($r) => (array) $r)
        ->map(fn ($r) => array_intersect_key($r, array_flip($cols)))->all();
    $status = $rows('issue_status_history', $a, ['from_status_id', 'to_status_id', 'to_category', 'user_id', 'agent_id', 'via', 'created_at']);
    expect($status)->toEqual([
        ['from_status_id' => null, 'to_status_id' => $statuses['To Do'], 'to_category' => 'to_do', 'user_id' => $user, 'agent_id' => null, 'via' => 'web', 'created_at' => $t(1)],
        ['from_status_id' => $statuses['To Do'], 'to_status_id' => $statuses['In Progress'], 'to_category' => 'in_progress', 'user_id' => $user, 'agent_id' => $agent, 'via' => 'api', 'created_at' => $t(2)],
        ['from_status_id' => $statuses['In Progress'], 'to_status_id' => $statuses['Done'], 'to_category' => 'done', 'user_id' => $user, 'agent_id' => null, 'via' => 'web', 'created_at' => $t(5)],
    ]);
    expect($rows('sprint_scope_events', $a, ['sprint_id', 'event', 'estimate', 'created_at']))->toEqual([
        ['sprint_id' => $s1, 'event' => 'joined', 'estimate' => 5, 'created_at' => $t(4)],
        ['sprint_id' => $s1, 'event' => 'left', 'estimate' => 5, 'created_at' => $t(6)],
        ['sprint_id' => $s2, 'event' => 'joined', 'estimate' => 5, 'created_at' => $t(6)],
    ]);
    expect($rows('estimate_history', $a, ['from_estimate', 'to_estimate', 'created_at']))->toEqual([
        ['from_estimate' => null, 'to_estimate' => 5, 'created_at' => $t(3)],
        ['from_estimate' => 5, 'to_estimate' => 8, 'created_at' => $t(7)],
    ]);

    // A soft-deleted issue gets its history too.
    expect($rows('issue_status_history', $d, ['to_category']))->toEqual([['to_category' => 'to_do'], ['to_category' => 'done']]);

    // No activity at all: one initial row each, at the issue's created_at, from what it is now.
    expect($rows('issue_status_history', $b, ['from_status_id', 'to_status_id', 'to_category', 'created_at']))->toEqual([['from_status_id' => null, 'to_status_id' => $statuses['In Progress'], 'to_category' => 'in_progress', 'created_at' => $t(1)]])
        ->and($rows('sprint_scope_events', $b, ['sprint_id', 'event', 'estimate', 'created_at']))->toEqual([['sprint_id' => $s1, 'event' => 'joined', 'estimate' => 3, 'created_at' => $t(1)]])
        ->and($rows('estimate_history', $b, ['from_estimate', 'to_estimate']))->toEqual([['from_estimate' => null, 'to_estimate' => 3]]);

    // A name that is no longer a status of the project is skipped and counted; the initial row it started from stays. An unmapped "from" drops the initial row too and counts it (issue E: 2).
    expect($rows('issue_status_history', $c, ['to_status_id']))->toEqual([['to_status_id' => $statuses['To Do']]])
        ->and($output)->toContain('skipped 3');

    expect(Artisan::call('migrate:rollback', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable('issue_status_history'))->toBeFalse()->and(Schema::hasTable('sprint_scope_events'))->toBeFalse()->and(Schema::hasTable('estimate_history'))->toBeFalse();

    Artisan::call('migrate', ['--force' => true]);
});

test('backfill: left keeps the estimate from before the activity, joined takes the new one; activities follow created_at, not id', function () {
    $paths = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getPathname())
        ->filter(fn ($p) => basename($p) < '2026_11_26')->sort()->values()->all();
    Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]);

    $this->freezeTime();
    $base = now()->startOfSecond();
    $t = fn (int $day) => $base->copy()->subDays(30 - $day)->toDateTimeString();
    $user = DB::table('users')->insertGetId(['name' => 'demo', 'email' => 'demo@example.com', 'password' => 'x', 'created_at' => $t(0), 'updated_at' => $t(0)]);
    $ws = DB::table('workspaces')->insertGetId(['name' => 'Acme', 'slug' => 'acme', 'created_at' => $t(0), 'updated_at' => $t(0)]);
    $project = DB::table('projects')->insertGetId(['workspace_id' => $ws, 'key' => 'WEB', 'name' => 'Web', 'created_at' => $t(0), 'updated_at' => $t(0)]);
    $todo = DB::table('statuses')->insertGetId(['workspace_id' => $ws, 'name' => 'To Do', 'category' => 'to_do', 'created_at' => $t(0), 'updated_at' => $t(0)]);
    $done = DB::table('statuses')->insertGetId(['workspace_id' => $ws, 'name' => 'Done', 'category' => 'done', 'created_at' => $t(0), 'updated_at' => $t(0)]);
    foreach ([['To Do', $todo], ['Done', $done]] as $i => [$name, $id]) {
        $column = DB::table('columns')->insertGetId(['project_id' => $project, 'name' => $name, 'position' => $i, 'created_at' => $t(0), 'updated_at' => $t(0)]);
        DB::table('column_statuses')->insert(['column_id' => $column, 'status_id' => $id, 'position' => 0]);
    }
    $sprint = fn (string $name) => DB::table('sprints')->insertGetId(['project_id' => $project, 'name' => $name, 'created_at' => $t(0), 'updated_at' => $t(0)]);
    [$a, $b] = [$sprint('A'), $sprint('B')];
    $issue = DB::table('issues')->insertGetId([
        'project_id' => $project, 'number' => 1, 'title' => 'i', 'type' => 'task', 'priority' => 'medium', 'status_id' => $done, 'sprint_id' => $b, 'story_points' => 8,
        'rank' => 1024, 'reporter_id' => $user, 'created_at' => $t(1), 'updated_at' => $t(9),
    ]);
    $activity = fn (int $day, array $changes) => DB::table('activities')->insert(['issue_id' => $issue, 'user_id' => $user, 'action' => 'updated', 'changes' => json_encode($changes), 'via' => 'web', 'created_at' => $t($day)]);
    // Inserted out of time order: the later move (day 6) has the lower id.
    $activity(6, ['status' => ['from' => 'To Do', 'to' => 'Done']]);
    $activity(5, ['sprint' => ['from' => 'A', 'to' => 'B'], 'story_points' => ['from' => 5, 'to' => 8]]);
    $activity(2, ['sprint' => ['from' => null, 'to' => 'A']]);

    ob_start();
    Artisan::call('migrate', ['--force' => true]);
    ob_end_clean();

    expect(DB::table('sprint_scope_events')->where('issue_id', $issue)->orderBy('id')->get(['sprint_id', 'event', 'estimate', 'created_at'])->map(fn ($r) => (array) $r)->all())->toEqual([
        ['sprint_id' => $a, 'event' => 'joined', 'estimate' => 5, 'created_at' => $t(2)],
        ['sprint_id' => $a, 'event' => 'left', 'estimate' => 5, 'created_at' => $t(5)],
        ['sprint_id' => $b, 'event' => 'joined', 'estimate' => 8, 'created_at' => $t(5)],
    ]);
    // Status rows: the initial row, then the move, in created_at order.
    expect(DB::table('issue_status_history')->where('issue_id', $issue)->orderBy('id')->pluck('created_at')->all())->toEqual([$t(1), $t(6)]);
});
