<?php

namespace App\Http\Resources;

use App\Models\Issue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps BoardService::board(): columns with their statuses and compact issue cards. */
class BoardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'columns' => $this->resource['columns']->map(fn ($column) => [
                'name' => $column->name,
                'position' => $column->position,
                'statuses' => StatusResource::collection($column->statuses)->resolve(),
                'issues' => $column->issues->map(fn (Issue $i) => self::card($i))->values()->all(),
            ])->values()->all(),
            'truncated' => $this->resource['truncated'],
        ];
    }

    public static function card(Issue $issue): array
    {
        return [
            'key' => $issue->key,
            'title' => $issue->title,
            'type' => $issue->type->value,
            'priority' => $issue->priority->value,
            'status' => $issue->status->name,
            'category' => $issue->status->category->value,
            'status_color' => $issue->status->color,
            'resolution' => $issue->resolution?->value,
            'resolved_at' => $issue->resolved_at?->toIso8601String(),
            'assignee' => $issue->assignee ? ['name' => $issue->assignee->name, 'email' => $issue->assignee->email] : null,
            'labels' => $issue->labels->pluck('name')->all(),
            'story_points' => $issue->story_points,
            'due_date' => $issue->due_date?->format('Y-m-d'),
            'parent' => $issue->parent ? $issue->project->key.'-'.$issue->parent->number : null,
            // Spec 7.11: what an edit from a list sends back (the board's resolution chip, an agent's update_issue).
            'version' => $issue->version,
            // Spec 7.5: only where the query asked for it (Issue::withBlocked).
            ...(array_key_exists('blocked', $issue->getAttributes()) ? ['blocked' => (bool) $issue->getAttributes()['blocked']] : []),
        ];
    }
}
