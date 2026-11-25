<?php

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            // The issue's version after this change (spec 7.11).
            'issue_version' => $this->issue_version,
            // approval_request: {id, to} on an approved or rejected agent request (A2); stored beside the field changes.
            'changes' => collect($this->changes)->except(['approval_request', 'comment_id'])->all() ?: null,
            'approval_request' => $this->when(isset($this->changes['approval_request']), fn () => $this->changes['approval_request']),
            'user' => IssueResource::user($this->user),
            'agent' => $this->whenLoaded('agent', fn () => IssueResource::agent($this->agent)),
            'via' => $this->via,
            'token_name' => $this->token_name,
            'created_at' => $this->created_at,
        ];
    }
}
