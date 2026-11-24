<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 7.5 (step 2): issue links, stored once in the forward direction. 2-D1: step 1 kept a Duplicate's original only
 * in the activity log (`duplicate_of`); each issue still resolved as Duplicate gets a `duplicates` link to it when the
 * original still exists in the same workspace. The rest are skipped and counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-runnable without losing links made since: the table is created once, and the backfill only adds what's missing.
        if (! Schema::hasTable('issue_links')) {
            Schema::create('issue_links', function (Blueprint $table) {
                $table->id();
                // Purging an issue removes its links (FK cascade); a soft delete only hides them.
                $table->foreignId('source_issue_id')->constrained('issues')->cascadeOnDelete();
                $table->foreignId('target_issue_id')->constrained('issues')->cascadeOnDelete();
                $table->string('type', 20);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
                $table->string('via', 20);
                $table->timestamp('created_at')->useCurrent();
                // One original per Duplicate, held by MySQL: the source only on duplicates rows, unique (NULLs don't clash).
                $table->unsignedBigInteger('duplicate_source_id')->nullable()->virtualAs("IF(type = 'duplicates', source_issue_id, NULL)")->unique();
                $table->unique(['source_issue_id', 'target_issue_id', 'type']);
                // The other direction: "is blocked by" lookups and the Blocked flag.
                $table->index(['target_issue_id', 'type']);
            });
        }

        [$created, $skipped] = [0, 0];
        // insertOrIgnore against the unique indexes: a link already there (or another original made since) stays as it is.
        // shortcut: two queries per Duplicate issue; batch the lookups if a workspace has tens of thousands of them.
        // Soft-deleted issues too: the query builder has no SoftDeletes scope, and their links come back on restore.
        DB::table('issues')->join('projects', 'projects.id', '=', 'issues.project_id')->where('issues.resolution', 'duplicate')
            ->select('issues.id', 'projects.workspace_id')
            ->eachById(function ($issue) use (&$created, &$skipped) {
                $entry = DB::table('activities')->where('issue_id', $issue->id)->whereNotNull('changes->duplicate_of->to')
                    ->orderByDesc('id')->first(['user_id', 'agent_id', 'via', 'created_at', 'changes']);
                if (! $entry) {
                    $skipped++;

                    return;
                }
                $key = json_decode($entry->changes, true)['duplicate_of']['to'];
                $original = preg_match('/^([A-Za-z][A-Za-z0-9]{1,9})-(\d+)$/', (string) $key, $m)
                    ? DB::table('issues')->join('projects', 'projects.id', '=', 'issues.project_id')
                        ->where('projects.workspace_id', $issue->workspace_id)->where('projects.key', strtoupper($m[1]))->where('issues.number', $m[2])
                        ->value('issues.id')
                    : null;
                if (! $original || $original === $issue->id) {
                    $skipped++;

                    return;
                }
                $created += DB::table('issue_links')->insertOrIgnore([
                    'source_issue_id' => $issue->id, 'target_issue_id' => $original, 'type' => 'duplicates',
                    'created_by' => $entry->user_id, 'agent_id' => $entry->agent_id, 'via' => $entry->via, 'created_at' => $entry->created_at,
                ]);
            }, 200, 'issues.id', 'id');

        echo "Duplicate links: {$created} created, {$skipped} skipped (original not recorded, purged, missing or in another workspace).\n";
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_links');
    }
};
