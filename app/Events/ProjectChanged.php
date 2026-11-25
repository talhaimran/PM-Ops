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
        if (! self::broadcasting()) {
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

    /**
     * Nothing on the wire: viewers only reload, and a socket kept open after losing access (auth runs at subscribe, scan
     * F1/F2) learns no issue key or kind, just that the project changed and when.
     */
    public function broadcastWith(): array
    {
        return [];
    }

    /** Without a broadcaster nothing is queued, so a server with no queue worker doesn't pile up jobs. */
    public function broadcastWhen(): bool
    {
        return self::broadcasting();
    }

    /** False for the null broadcaster: BROADCAST_CONNECTION=null reaches config as a real null (env()), or 'null' when unset. */
    public static function broadcasting(): bool
    {
        return ! in_array(config('broadcasting.default'), [null, 'null'], true);
    }
}
