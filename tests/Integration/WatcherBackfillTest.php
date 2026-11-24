<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// A real migration (DDL commits in MySQL), so it sits with the truncating Integration tests.

test('the watchers migration backfills reporter, assignee and commenters with access, once', function () {
    $paths = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getPathname())
        ->filter(fn ($p) => basename($p) < '2026_12_01')->sort()->values()->all();
    Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]);

    $now = now()->toDateTimeString();
    $user = fn (string $n) => DB::table('users')->insertGetId(['name' => $n, 'email' => "{$n}@example.com", 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    [$rep, $asg, $com, $out, $deletedCommenter] = [$user('rep'), $user('asg'), $user('com'), $user('out'), $user('del')];
    $ws = DB::table('workspaces')->insertGetId(['name' => 'Acme', 'slug' => 'acme', 'created_at' => $now, 'updated_at' => $now]);
    $project = DB::table('projects')->insertGetId(['workspace_id' => $ws, 'key' => 'WEB', 'name' => 'Web', 'created_at' => $now, 'updated_at' => $now]);
    foreach ([$rep, $asg, $com, $deletedCommenter] as $id) {
        DB::table('project_user')->insert(['project_id' => $project, 'user_id' => $id, 'role' => 'member', 'created_at' => $now, 'updated_at' => $now]);
    }
    $status = DB::table('statuses')->insertGetId(['workspace_id' => $ws, 'name' => 'To Do', 'category' => 'to_do', 'created_at' => $now, 'updated_at' => $now]);
    $issue = fn (int $n, array $extra = []) => DB::table('issues')->insertGetId([
        'project_id' => $project, 'number' => $n, 'title' => "i{$n}", 'type' => 'task', 'priority' => 'medium', 'status_id' => $status,
        'rank' => $n * 1024, 'reporter_id' => $rep, 'created_at' => $now, 'updated_at' => $now, ...$extra,
    ]);
    $live = $issue(1, ['assignee_id' => $asg]);
    $deleted = $issue(2, ['deleted_at' => $now]);
    $strangers = $issue(3, ['reporter_id' => $out]);
    $comment = fn (int $i, int $u, ?string $gone = null) => DB::table('comments')->insert(['issue_id' => $i, 'user_id' => $u, 'body' => 'x', 'deleted_at' => $gone, 'created_at' => $now, 'updated_at' => $now]);
    $comment($live, $com);
    $comment($live, $deletedCommenter, $now);
    $comment($deleted, $com);

    ob_start();
    Artisan::call('migrate', ['--force' => true]);
    ob_end_clean();

    $rows = fn () => DB::table('issue_watchers')->orderBy('issue_id')->orderBy('user_id')->get(['issue_id', 'user_id', 'source'])->map(fn ($r) => (array) $r)->all();
    expect($rows())->toEqual([
        ['issue_id' => $live, 'user_id' => $rep, 'source' => 'auto'],
        ['issue_id' => $live, 'user_id' => $asg, 'source' => 'auto'],
        ['issue_id' => $live, 'user_id' => $com, 'source' => 'auto'],
    ])->and(Schema::hasColumn('users', 'auto_watch'))->toBeTrue()
        ->and((bool) DB::table('users')->where('id', $rep)->value('auto_watch'))->toBeTrue();
    // No row for the deleted issue, the person with no access, or the deleted comment's author.
    expect(DB::table('issue_watchers')->whereIn('issue_id', [$deleted, $strangers])->count())->toBe(0);

    // Re-running adds nothing.
    $before = $rows();
    (require database_path('migrations/2026_12_01_000001_create_issue_watchers.php'))->backfill();
    expect($rows())->toEqual($before);
});
