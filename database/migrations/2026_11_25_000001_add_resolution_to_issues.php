<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 7.4 (step 1): resolution and resolved_at. Every issue already in a Done-category status is resolved as Done
 * (1-R7), at the time of the last activity that moved it into its current status, else its updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->string('resolution', 20)->nullable()->after('status_id');
            $table->timestamp('resolved_at')->nullable()->after('resolution');
            $table->index('resolution');
        });

        // Soft-deleted issues too: the query builder has no SoftDeletes scope.
        DB::table('issues')->join('statuses', 'statuses.id', '=', 'issues.status_id')->where('statuses.category', 'done')
            ->select('issues.id', 'issues.updated_at', 'statuses.name')
            ->chunkById(500, function ($issues) {
                // shortcut: matches the activity's status name, so a status renamed since (step 1 allows it) falls back to updated_at.
                $moves = DB::table('activities')->whereIn('issue_id', $issues->pluck('id'))->whereNotNull('changes->status->to')
                    ->get(['issue_id', 'created_at', 'changes'])
                    ->groupBy('issue_id');

                foreach ($issues as $issue) {
                    $last = ($moves[$issue->id] ?? collect())
                        ->filter(fn ($a) => (json_decode($a->changes, true)['status']['to'] ?? null) === $issue->name)
                        ->max('created_at');
                    DB::table('issues')->where('id', $issue->id)->update(['resolution' => 'done', 'resolved_at' => $last ?? $issue->updated_at]);
                }
            }, 'issues.id', 'id');
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['resolution']);
            $table->dropColumn(['resolution', 'resolved_at']);
        });
    }
};
