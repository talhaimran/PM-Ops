<?php

namespace App\Events;

use App\Models\Issue;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * 3-R14: something on a project's board, list or issue page changed; viewers reload their own props. Ids, the issue key and
 * a kind (activity action, reaction, comment_edited, ranked…) only: never titles, text, field values or emails.
 */
class ProjectChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(public int $workspaceId, public int $projectId, public ?string $issueKey, public string $kind) {}

    /** Broadcast that $issueId (deleted ones too) changed; nothing when it is gone for good. */
    public static function about(int $issueId, string $kind): void
    {
        // No broadcaster: skip the lookup (this runs for every activity row).
        if (config('broadcasting.default') === 'null') {
            return;
        }
        $issue = Issue::withTrashed()->with('project')->find($issueId);
        $issue && event(new self($issue->project->workspace_id, $issue->project_id, $issue->key, $kind));
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("workspace.{$this->workspaceId}.project.{$this->projectId}");
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    /** Without a broadcaster nothing is queued, so a server with no queue worker doesn't pile up jobs. */
    public function broadcastWhen(): bool
    {
        return config('broadcasting.default') !== 'null';
    }
}
