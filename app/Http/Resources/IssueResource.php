<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SelectsFields;
use App\Models\Agent;
use App\Models\Issue;
use App\Models\User;
use App\Services\LinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Issue */
class IssueResource extends JsonResource
{
    use SelectsFields;

    public function toArray(Request $request): array
    {
        $projectKey = $this->project->key;
        $link = fn (?Issue $i) => $i ? ['key' => "{$projectKey}-{$i->number}", 'title' => $i->title] : null;
        $child = fn (Issue $i) => [...$link($i), 'type' => $i->type->value, 'status' => $i->status->name, 'category' => $i->status->category->value, 'status_color' => $i->status->color];

        // Nested collections are resolved to plain arrays: Inertia would wrap nested resources in {data: …}.
        return $this->selected([
            'id' => $this->id,
            'key' => "{$projectKey}-{$this->number}",
            // Spec 7.11: what every edit sends back; `fields` never drops it.
            'version' => $this->version,
            'project' => $projectKey,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type->value,
            'priority' => $this->priority->value,
            'status' => $this->status->name,
            'category' => $this->status->category->value,
            // Null = the category's colour (spec 7.4).
            'status_color' => $this->status->color,
            'resolution' => $this->resolution?->value,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'assignee' => self::user($this->assignee),
            'reporter' => self::user($this->reporter),
            'labels' => $this->labels->pluck('name')->all(),
            'story_points' => $this->story_points,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'start_date' => $this->start_date?->format('Y-m-d'),
            'epic' => $link($this->epic),
            'parent' => $link($this->parent),
            'sprint' => $this->sprint ? ['id' => $this->sprint->id, 'name' => $this->sprint->name, 'state' => $this->sprint->state->value] : null,
            // Spec 7.5: an unresolved blocker the viewer can see; set on lists (Issue::withBlocked) and the single issue.
            'blocked' => $this->when(array_key_exists('blocked', $this->resource->getAttributes()), fn () => (bool) $this->resource->getAttributes()['blocked']),
            // Spec 5.1: the Focus group on My issues rows (null for done ones); only there.
            'focus' => $this->when(array_key_exists('focus', $this->resource->getAttributes()), fn () => $this->resource->getAttributes()['focus']),
            // Grouped by type name as read from this issue ("is blocked by"); only on the single issue (IssueService::loadFull).
            'links' => $this->when($this->resource->links !== null, fn () => (object) $this->resource->links),
            // Spec 7.5: a successor starting before its predecessor's due date, on both issues; only on the single issue (IssueService::loadFull).
            'schedule_warning' => $this->when($this->resource->links !== null, fn () => $this->resource->scheduleWarning),
            // 2-R6: "ask" saved the due date and moved nothing; these are the dates that would apply (send shift_successors: true).
            'proposed_shifts' => $this->when($this->resource->proposedShifts !== null, fn () => $this->resource->proposedShifts),
            'warning' => $this->when($this->resource->proposedShifts !== null, fn () => LinkService::shiftWarning($this->resource, $this->resource->proposedShifts)),
            'subtasks' => $this->whenLoaded('subtasks', fn () => $this->subtasks->map($child)->all()),
            'epic_issues' => $this->whenLoaded('epicIssues', fn () => $this->epicIssues->map($child)->all()),
            'comments' => $this->whenLoaded('comments', fn () => CommentResource::collection($this->comments)->resolve()),
            'pending_approvals' => $this->whenLoaded('pendingApprovals', fn () => ApprovalRequestResource::collection($this->pendingApprovals)->resolve()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ]);
    }

    /** @return array{id: int, name: string, email: string}|null */
    /** {name, owner} of the agent that acted, for the agent label; null for a person or when not loaded (load agent.owner). */
    public static function agent(?Agent $agent): ?array
    {
        return $agent ? ['name' => $agent->name, 'owner' => $agent->owner->name] : null;
    }

    public static function user(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null;
    }
}
