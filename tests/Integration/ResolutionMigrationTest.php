<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// Real migrations (DDL commits in MySQL), so it sits with the truncating Integration tests.

function migrateToBeforeResolution(): void
{
    $paths = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getPathname())
        ->filter(fn ($p) => basename($p) < '2026_11_25')->sort()->values()->all();

    Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]);
}

test('1-R7: Done-category issues get done and resolved_at from the move into Done, else updated_at; others stay null', function () {
    migrateToBeforeResolution();
    $now = now()->startOfSecond();
    $user = DB::table('users')->insertGetId(['name' => 'demo', 'email' => 'demo@example.com', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $ws = DB::table('workspaces')->insertGetId(['name' => 'Acme', 'slug' => 'acme', 'created_at' => $now, 'updated_at' => $now]);
    $project = DB::table('projects')->insertGetId(['workspace_id' => $ws, 'key' => 'WEB', 'name' => 'Web', 'created_at' => $now, 'updated_at' => $now]);
    $status = fn (string $name, string $category) => DB::table('statuses')->insertGetId(['workspace_id' => $ws, 'name' => $name, 'category' => $category, 'created_at' => $now, 'updated_at' => $now]);
    [$todo, $shipped, $done] = [$status('To Do', 'to_do'), $status('Shipped', 'done'), $status('Done', 'done')];
    $issue = fn (int $n, int $statusId, $updated, $deleted = null) => DB::table('issues')->insertGetId([
        'project_id' => $project, 'number' => $n, 'title' => "i{$n}", 'type' => 'task', 'priority' => 'medium', 'status_id' => $statusId, 'rank' => $n * 1024,
        'created_at' => $now->copy()->subDays(30), 'updated_at' => $updated, 'deleted_at' => $deleted,
    ]);
    $moved = $now->copy()->subDays(10);
    $edited = $now->copy()->subDays(2);
    $withMove = $issue(1, $done, $edited);
    $withoutMove = $issue(2, $shipped, $edited);
    $deleted = $issue(3, $done, $edited, $now);
    $open = $issue(4, $todo, $edited);
    $activity = fn (int $id, string $at, array $changes) => DB::table('activities')->insert(['issue_id' => $id, 'user_id' => $user, 'action' => 'updated', 'changes' => json_encode($changes), 'via' => 'web', 'created_at' => $at]);
    // An older move into Done, then the latest one, then a later edit that isn't a status change.
    $activity($withMove, $moved->copy()->subDays(5)->toDateTimeString(), ['status' => ['from' => 'To Do', 'to' => 'Done']]);
    $activity($withMove, $moved->toDateTimeString(), ['status' => ['from' => 'In Progress', 'to' => 'Done']]);
    $activity($withMove, $edited->toDateTimeString(), ['title' => ['from' => 'a', 'to' => 'b']]);
    // A move that went elsewhere doesn't count for an issue now in Done.
    $activity($withoutMove, $moved->toDateTimeString(), ['status' => ['from' => 'Done', 'to' => 'Shipped']]);
    $activity($open, $moved->toDateTimeString(), ['status' => ['from' => 'Done', 'to' => 'To Do']]);

    Artisan::call('migrate', ['--force' => true]);

    $row = fn (int $id) => DB::table('issues')->find($id);
    expect($row($withMove)->resolution)->toBe('done')->and($row($withMove)->resolved_at)->toBe($moved->toDateTimeString())
        ->and($row($withoutMove)->resolution)->toBe('done')
        // The activity says "Shipped" is where it went; that is the move into the current status.
        ->and($row($withoutMove)->resolved_at)->toBe($moved->toDateTimeString())
        ->and($row($deleted)->resolution)->toBe('done')->and($row($deleted)->resolved_at)->toBe($edited->toDateTimeString())
        ->and($row($open)->resolution)->toBeNull()->and($row($open)->resolved_at)->toBeNull();

    expect(Artisan::call('migrate:rollback', ['--force' => true]))->toBe(0)
        ->and(Schema::hasColumn('issues', 'resolution'))->toBeFalse()
        ->and(Schema::hasColumn('issues', 'resolved_at'))->toBeFalse();

    Artisan::call('migrate', ['--force' => true]);
});
