<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Gives every Duplicate issue without a duplicates link one to the original its activity log names (the newest
 * `duplicate_of`), when that original still exists in the same workspace. Used by the links migration (2-D1) and the
 * daily links:reconcile-duplicates (a Close as Duplicate whose after-commit link failed). Query builder only, so the
 * migration keeps working whatever the models become.
 */
class DuplicateLinkRepair
{
    /** @return array{0: int, 1: int} links made, and Duplicates skipped (original not recorded, purged, missing or in another workspace) */
    public static function run(): array
    {
        [$made, $skipped] = [0, 0];
        // shortcut: two queries per Duplicate issue; batch the lookups if a workspace has tens of thousands of them.
        // Soft-deleted issues too: the query builder has no SoftDeletes scope, and their links come back on restore.
        DB::table('issues')->join('projects', 'projects.id', '=', 'issues.project_id')->where('issues.resolution', 'duplicate')
            ->whereNotExists(fn ($q) => $q->from('issue_links')->whereColumn('issue_links.source_issue_id', 'issues.id')->where('issue_links.type', 'duplicates'))
            ->select('issues.id', 'projects.workspace_id')
            ->eachById(function ($issue) use (&$made, &$skipped) {
                $entry = DB::table('activities')->where('issue_id', $issue->id)->whereNotNull('changes->duplicate_of->to')
                    ->orderByDesc('id')->first(['user_id', 'agent_id', 'via', 'created_at', 'changes']);
                $key = $entry ? json_decode($entry->changes, true)['duplicate_of']['to'] : null;
                $original = preg_match('/^([A-Za-z][A-Za-z0-9]{1,9})-(\d+)$/', (string) $key, $m)
                    ? DB::table('issues')->join('projects', 'projects.id', '=', 'issues.project_id')
                        ->where('projects.workspace_id', $issue->workspace_id)->where('projects.key', strtoupper($m[1]))->where('issues.number', $m[2])
                        ->value('issues.id')
                    : null;
                if (! $original || $original === $issue->id) {
                    $skipped++;

                    return;
                }
                // insertOrIgnore against the unique indexes: a link made meanwhile (or another original) stays as it is.
                $made += DB::table('issue_links')->insertOrIgnore([
                    'source_issue_id' => $issue->id, 'target_issue_id' => $original, 'type' => 'duplicates',
                    'created_by' => $entry->user_id, 'agent_id' => $entry->agent_id, 'via' => $entry->via, 'created_at' => $entry->created_at,
                ]);
            }, 200, 'issues.id', 'id');

        return [$made, $skipped];
    }
}
