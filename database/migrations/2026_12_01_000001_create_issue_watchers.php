<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 7.10 (step 3, 3-R2): who watches an issue, and each person's `auto_watch` choice. Backfilled so existing
 * work starts with its reporter, assignee and commenters watching.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL isn't transactional: drop what a failed earlier run left. The table is new, so nothing is lost.
        Schema::dropIfExists('issue_watchers');

        Schema::create('issue_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 6)->default('manual');
            $table->timestamps();
            $table->unique(['issue_id', 'user_id']);
            $table->index('user_id');
        });

        if (! Schema::hasColumn('users', 'auto_watch')) {
            Schema::table('users', fn (Blueprint $table) => $table->boolean('auto_watch')->default(true));
        }

        $this->backfill();
    }

    /** Reporter, assignee and commenters of every non-deleted issue, when they are members of its project. Safe to re-run. */
    public function backfill(): void
    {
        DB::statement(<<<'SQL'
            INSERT IGNORE INTO issue_watchers (issue_id, user_id, source, created_at, updated_at)
            SELECT w.issue_id, w.user_id, 'auto', NOW(), NOW()
            FROM (
                SELECT id AS issue_id, reporter_id AS user_id FROM issues WHERE reporter_id IS NOT NULL
                UNION SELECT id, assignee_id FROM issues WHERE assignee_id IS NOT NULL
                UNION SELECT issue_id, user_id FROM comments WHERE deleted_at IS NULL AND user_id IS NOT NULL
            ) w
            JOIN issues i ON i.id = w.issue_id AND i.deleted_at IS NULL
            JOIN project_user pu ON pu.project_id = i.project_id AND pu.user_id = w.user_id
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_watchers');
        if (Schema::hasColumn('users', 'auto_watch')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('auto_watch'));
        }
    }
};
