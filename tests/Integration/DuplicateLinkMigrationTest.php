<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// 2-D1: step 1 recorded a Duplicate's original only in the activity log; the links migration turns that into links.
// Real migrations (DDL commits in MySQL), so it sits with the truncating Integration tests.

test('2-D1: a Duplicate\'s recorded original becomes a duplicates link; missing or other-workspace originals are counted; re-running adds nothing', function () {
    $migration = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getPathname())->first(fn ($p) => str_contains($p, 'create_issue_links_table'));
    $paths = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getPathname())
        ->filter(fn ($p) => basename($p) < basename($migration))->sort()->values()->all();
    Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]);

    $now = now()->startOfSecond()->toDateTimeString();
    $user = DB::table('users')->insertGetId(['name' => 'demo', 'email' => 'demo@example.com', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $agent = null;
    $workspace = fn (string $slug) => DB::table('workspaces')->insertGetId(['name' => $slug, 'slug' => $slug, 'created_at' => $now, 'updated_at' => $now]);
    [$ws, $other] = [$workspace('acme'), $workspace('other')];
    $agent = DB::table('agents')->insertGetId(['workspace_id' => $ws, 'owner_user_id' => $user, 'name' => 'bot', 'permission_level' => 'write', 'created_at' => $now, 'updated_at' => $now]);
    $project = fn (int $w, string $key) => DB::table('projects')->insertGetId(['workspace_id' => $w, 'key' => $key, 'name' => $key, 'created_at' => $now, 'updated_at' => $now]);
    [$web, $ops] = [$project($ws, 'WEB'), $project($other, 'OPS')];
    $done = DB::table('statuses')->insertGetId(['workspace_id' => $ws, 'name' => 'Done', 'category' => 'done', 'created_at' => $now, 'updated_at' => $now]);
    $issue = fn (int $projectId, int $n, ?string $resolution = null, ?string $deleted = null) => DB::table('issues')->insertGetId([
        'project_id' => $projectId, 'number' => $n, 'title' => "i{$n}", 'type' => 'task', 'priority' => 'medium', 'status_id' => $done,
        'resolution' => $resolution, 'rank' => $n * 1024, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $deleted,
    ]);
    $activity = fn (int $id, string $original, string $at = '2026-11-01 10:00:00', ?int $agentId = null) => DB::table('activities')->insert([
        'issue_id' => $id, 'user_id' => $user, 'agent_id' => $agentId, 'action' => 'updated', 'via' => 'mcp', 'created_at' => $at,
        'changes' => json_encode(['status' => ['from' => 'To Do', 'to' => 'Done'], 'duplicate_of' => ['from' => null, 'to' => $original]]),
    ]);

    $original = $issue($web, 3);
    $deletedOriginal = $issue($web, 4, deleted: $now);
    $issue($ops, 3);
    $dup = $issue($web, 1, 'duplicate');
    $activity($dup, 'WEB-9', '2026-10-01 10:00:00');
    $activity($dup, 'WEB-3', '2026-11-01 10:00:00', $agent);
    $toDeleted = $issue($web, 5, 'duplicate');
    $activity($toDeleted, 'WEB-4');
    $purged = $issue($web, 2, 'duplicate');
    $activity($purged, 'WEB-99');
    $otherWorkspace = $issue($web, 6, 'duplicate');
    $activity($otherWorkspace, 'OPS-3');
    // A deleted Duplicate gets its link too; one with no recorded original is counted.
    $deletedDup = $issue($web, 8, 'duplicate', $now);
    $activity($deletedDup, 'WEB-3');
    $issue($web, 9, 'duplicate');
    // No longer a Duplicate: no link.
    $reopened = $issue($web, 7, 'done');
    $activity($reopened, 'WEB-3');

    ob_start();
    Artisan::call('migrate', ['--force' => true]);
    $output = ob_get_clean();

    $links = fn () => DB::table('issue_links')->orderBy('id')->get()->map(fn ($l) => [$l->source_issue_id, $l->target_issue_id, $l->type, $l->created_by, $l->agent_id, $l->via, $l->created_at])->all();
    $expected = [
        [$dup, $original, 'duplicates', $user, $agent, 'mcp', '2026-11-01 10:00:00'],
        // A soft-deleted original still exists: the link hides with it and comes back on restore.
        [$toDeleted, $deletedOriginal, 'duplicates', $user, null, 'mcp', '2026-11-01 10:00:00'],
        [$deletedDup, $original, 'duplicates', $user, null, 'mcp', '2026-11-01 10:00:00'],
    ];
    expect($links())->toBe($expected)
        ->and($output)->toContain('Duplicate links: 3 created, 3 skipped (original not recorded, purged, missing, in another workspace or removed on purpose).');

    // Re-runnable without losing anything: links made since survive, and the backfill adds only what's missing.
    $made = ['created_by' => $user, 'via' => 'web', 'created_at' => '2026-11-20 10:00:00'];
    DB::table('issue_links')->insert([
        ['source_issue_id' => $original, 'target_issue_id' => $dup, 'type' => 'blocks', ...$made],
        ['source_issue_id' => $original, 'target_issue_id' => $purged, 'type' => 'relates to', ...$made],
    ]);
    ob_start();
    (require $migration)->up();
    $rerun = ob_get_clean();
    expect($links())->toBe([
        ...$expected,
        [$original, $dup, 'blocks', $user, null, 'web', '2026-11-20 10:00:00'],
        [$original, $purged, 'relates to', $user, null, 'web', '2026-11-20 10:00:00'],
    ])->and($rerun)->toContain('Duplicate links: 0 created, 3 skipped');

    // MySQL holds the one-original rule: a second duplicates link from the same issue fails on its index.
    expect(fn () => DB::table('issue_links')->insert(['source_issue_id' => $dup, 'target_issue_id' => $purged, 'type' => 'duplicates', ...$made]))
        ->toThrow(UniqueConstraintViolationException::class, 'duplicate_source_id');
});
