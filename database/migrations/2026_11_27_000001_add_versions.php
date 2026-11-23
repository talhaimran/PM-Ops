<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 7.11 (step 1): edit conflict protection. Issues and comments start at version 1; an activity row records the
 * issue's version after its change, so "what changed since version N" is one query. Pending approval requests were
 * asked at version 1, the version every issue starts at here.
 */
return new class extends Migration
{
    /** [table, column, after, nullable] */
    private const COLUMNS = [['issues', 'version', 'rank', false], ['comments', 'version', 'body', false], ['activities', 'issue_version', 'action', true]];

    public function up(): void
    {
        // MySQL DDL isn't transactional: each column is checked, so a run that failed part-way can simply be re-run.
        foreach (self::COLUMNS as [$table, $column, $after, $nullable]) {
            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $nullable ? $t->unsignedInteger($column)->nullable()->after($after) : $t->unsignedInteger($column)->default(1)->after($after));
            }
        }

        DB::table('approval_requests')->where('status', 'pending')->update(['action' => DB::raw("JSON_SET(action, '$.issue_version', 1)")]);
    }

    public function down(): void
    {
        DB::table('approval_requests')->where('status', 'pending')->update(['action' => DB::raw("JSON_REMOVE(action, '$.issue_version')")]);
        foreach (array_reverse(self::COLUMNS) as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
