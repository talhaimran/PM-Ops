<?php

namespace App\Http\Resources;

use App\Models\ApprovalRequest;
use App\Models\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An approval request (A2). Needs issue.project, issue.status and agent.owner loaded. `from` is the status the issue
 * had when the agent asked, `now` its status; `moved` says its status changed since, and `changed` that anything did
 * (spec 7.11), so approving needs a second confirm.
 * `to` is always a Done-category status.
 *
 * @mixin ApprovalRequest
 */
class ApprovalRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $issue = $this->issue;
        $moved = $issue->status_id !== $this->action['from_status_id'];
        // shortcut: one query per moved request for the old status; store its name in `action` if lists grow.
        $from = $moved ? Status::find($this->action['from_status_id']) : $issue->status;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'issue' => $issue->key,
            'title' => $issue->title,
            'from' => $from?->name,
            'from_category' => $from?->category->value,
            'from_color' => $from?->color,
            'to' => $this->action['to_status'],
            'now' => $issue->status->name,
            'moved' => $moved,
            'changed' => $issue->version !== ($this->action['issue_version'] ?? 1),
            'reason' => $this->action['reason'] ?? null,
            // Spec 7.4: how the agent says it ended; the person approving applies it with the move.
            'resolution' => $this->action['resolution'] ?? null,
            'duplicate_of' => $this->action['duplicate_of'] ?? null,
            'agent' => IssueResource::agent($this->agent),
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }

    /** REST 202 for a move held for a person: the request and the issue as it is now (other fields may have changed, A-L7). */
    public static function pending(ApprovalRequest $approval, JsonResource $issue): JsonResponse
    {
        return response()->json(['status' => 'pending_approval', 'approval_request' => self::make($approval)->resolve(), 'issue' => $issue->resolve()], 202);
    }

    /** "WEB-12 needs a person's approval to move to Done (request 41, expires …)." for MCP summaries. */
    public static function sentence(ApprovalRequest $approval): string
    {
        return "{$approval->issue->key} needs a person's approval to move to {$approval->action['to_status']} (request {$approval->id}, expires {$approval->expires_at->toIso8601String()}).";
    }
}
