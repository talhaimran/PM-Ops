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
    public function up(): void
    {
        // shortcut: not re-runnable after a partial failure, drop the new columns by hand first (MySQL DDL isn't transactional); move to a guarded migration if installs need it.
        Schema::table('issues', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1)->after('rank'));
        Schema::table('comments', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1)->after('body'));
        Schema::table('activities', fn (Blueprint $table) => $table->unsignedInteger('issue_version')->nullable()->after('action'));

        DB::table('approval_requests')->where('status', 'pending')->update(['action' => DB::raw("JSON_SET(action, '$.issue_version', 1)")]);
    }

    public function down(): void
    {
        DB::table('approval_requests')->where('status', 'pending')->update(['action' => DB::raw("JSON_REMOVE(action, '$.issue_version')")]);
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn('issue_version'));
        Schema::table('comments', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::table('issues', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
