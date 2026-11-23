<?php

use Illuminate\Support\Facades\Schema;

// MySQL DDL commits per statement, so a failed run can leave some columns behind; up() and down() must finish it.

test('the versions migration resumes after a partial up or down', function () {
    $migration = require database_path('migrations/2026_11_27_000001_add_versions.php');
    $has = fn () => [Schema::hasColumn('issues', 'version'), Schema::hasColumn('comments', 'version'), Schema::hasColumn('activities', 'issue_version')];

    try {
        // A failed up(): the first two columns were added, the third wasn't.
        $migration->down();
        Schema::table('issues', fn ($t) => $t->unsignedInteger('version')->default(1)->after('rank'));
        Schema::table('comments', fn ($t) => $t->unsignedInteger('version')->default(1)->after('body'));
        $migration->up();
        expect($has())->toBe([true, true, true]);

        // A failed down(): activities.issue_version was dropped already.
        Schema::table('activities', fn ($t) => $t->dropColumn('issue_version'));
        $migration->down();
        expect($has())->toBe([false, false, false]);
    } finally {
        $migration->down();
        $migration->up();
    }
});
