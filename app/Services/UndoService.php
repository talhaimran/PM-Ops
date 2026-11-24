<?php

namespace App\Services;

use App\Enums\Resolution;
use App\Models\Issue;

/**
 * Undo for the web UI (spec 5.1): a new change in the opposite direction through the same services.
 * The toast sends back the request data built here, plus `undo_of`: the issue's version after the change (token()).
 */
class UndoService
{
    /**
     * The marker an undo carries: the issue's version right after the change. The undo sends it back as the version it
     * read (spec 5.1, 7.11), so IssueService stops it with "Can't undo: …" (EditConflict) if the issue changed since;
     * a rank-only reorder doesn't change the version, so it never blocks an undo.
     */
    public function token(Issue $issue): int
    {
        return $issue->version;
    }

    /**
     * Request data that puts $fields back to what they are on $issue now, in the shared input shape.
     * Call it before the update.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function forUpdate(Issue $issue, array $fields): array
    {
        $issue->loadMissing(IssueService::DISPLAY);
        $key = fn (?Issue $linked) => $linked ? "{$issue->project->key}-{$linked->number}" : null;

        $current = [
            'title' => $issue->title,
            'description' => $issue->description,
            'type' => $issue->type->value,
            'priority' => $issue->priority->value,
            'status' => $issue->status->name,
            'resolution' => $this->resolution($issue),
            'assignee' => $issue->assignee?->email,
            'labels' => $issue->labels->pluck('name')->values()->all(),
            'story_points' => $issue->story_points,
            'due_date' => $issue->due_date?->format('Y-m-d'),
            'epic' => $key($issue->epic),
            'parent' => $key($issue->parent),
            'sprint' => $issue->sprint?->name,
        ];

        // A status change takes the resolution with it: reopening clears it, so the undo must say what it was.
        $data = array_intersect_key($current, array_flip(in_array('status', $fields, true) ? [...$fields, 'resolution'] : $fields));
        if (isset($data['resolution']) && $issue->resolution === Resolution::Duplicate) {
            $data['resolution'] = Resolution::Duplicate->value;
            // The original's key lives only in the activity log until duplicate links (step 2).
            $data['duplicate_of'] = $issue->activities()->latest('id')->get()->map(fn ($a) => $a->changes['duplicate_of']['to'] ?? null)->filter()->first();
        }

        return $data;
    }

    /**
     * Request data for the move endpoint that returns the issue to its status and place. Call it before the move.
     *
     * @return array<string, mixed>
     */
    public function forMove(Issue $issue): array
    {
        $issue->loadMissing(IssueService::DISPLAY);
        $previous = Issue::where('project_id', $issue->project_id)
            ->whereIn('status_id', BoardService::columnOf($issue)->statuses->modelKeys())
            ->where('type', '!=', $issue->type->value === 'epic' ? '' : 'epic')
            ->where('rank', '<', $issue->rank)
            ->orderByDesc('rank')
            ->first();

        return ['status' => $issue->status->name, ...($issue->resolution ? ['resolution' => $this->resolution($issue)] : [])] + ($previous ? ['after' => "{$issue->project->key}-{$previous->number}"] : ['position' => 0]);
    }

    /**
     * Request data for the rank endpoint that returns the issue to its sprint (or the backlog) and place. Call it before the move.
     *
     * @return array<string, mixed>
     */
    public function forRank(Issue $issue): array
    {
        $issue->loadMissing(IssueService::DISPLAY);
        // The same neighbours BacklogService::rank places against: the sprint's issues, or the open backlog.
        $previous = Issue::where('project_id', $issue->project_id)
            ->where('type', '!=', 'epic')
            ->when(
                $issue->sprint_id,
                fn ($q, $id) => $q->where('sprint_id', $id),
                fn ($q) => $q->whereNull('sprint_id')->open(),
            )
            ->where('rank', '<', $issue->rank)
            ->orderByDesc('rank')
            ->first();

        return ['sprint' => $issue->sprint?->name ?? 'backlog'] + ($previous ? ['after' => "{$issue->project->key}-{$previous->number}"] : ['position' => 0]);
    }

    /** shortcut: a Duplicate is put back as Done, because the original's key isn't stored until duplicate links (step 2); the activity keeps it. */
    private function resolution(Issue $issue): ?string
    {
        return ($issue->resolution === Resolution::Duplicate ? Resolution::Done : $issue->resolution)?->value;
    }
}
