<?php

namespace App\Http\Resources;

use App\Models\InboxItem;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An inbox line (spec 7.10). Needs issue.project, actor and agent.owner loaded. Agents show as "{Owner}'s agent" (spec 5.1).
 *
 * @mixin InboxItem
 */
class InboxItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'text' => NotificationService::describe($this->resource),
            'issue' => $this->issue?->key,
            'agent' => $this->agent ? ['name' => $this->agent->name, 'owner' => $this->agent->owner?->name ?? ''] : null,
            'actor' => $this->actor?->only(['name', 'email']),
            // The keys a daily summary names.
            'keys' => $this->event === 'agent_summary' ? collect($this->changes)->where('action', 'updated')->pluck('to')->values()->all() : [],
            'count' => $this->count,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
