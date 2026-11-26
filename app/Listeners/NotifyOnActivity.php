<?php

namespace App\Listeners;

use App\Events\ActivityRecorded;
use App\Models\Agent;
use App\Models\Issue;
use App\Models\Mention;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WatcherService;
use Illuminate\Support\Arr;
use Throwable;

/**
 * 3-R5: an issue change tells the issue's watchers (never the person who made it; an agent's change is its owner's, who gets
 * the daily summary instead). Comment added, status change, assignee change, resolve and reopen notify; labels, priority, title
 * and other fields don't. A change that mixes both notifies for the notifying part only.
 */
class NotifyOnActivity
{
    public function __construct(private NotificationService $notifications, private WatcherService $watchers) {}

    /** Runs in the request after the change committed: a failure here is reported, never turned into a 500 the client would retry. */
    public function handle(ActivityRecorded $event): void
    {
        try {
            $this->notify($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function notify(ActivityRecorded $event): void
    {
        $activity = $event->activity;
        // approval_request is the link to a request a person decided, not a field.
        $changes = Arr::except($activity->changes ?? [], ['approval_request', 'comment_id']);
        $triggers = [];

        if ($activity->action === 'commented') {
            $triggers[] = ['commented', ['action' => 'commented', 'from' => null, 'to' => null]];
        }
        $status = $changes['status'] ?? null;
        $resolution = $changes['resolution']['to'] ?? null;
        if ($resolution !== null) {
            $triggers[] = ['resolved', ['action' => 'resolved', 'from' => $status['from'] ?? null, 'to' => $resolution]];
        } elseif ($status && $activity->action === 'reopened') {
            $triggers[] = ['reopened', ['action' => 'reopened', 'from' => $status['from'], 'to' => $status['to']]];
        } elseif ($status) {
            $triggers[] = ['status_changed', ['action' => 'status_changed', 'from' => $status['from'], 'to' => $status['to']]];
        }
        if (isset($changes['assignee'])) {
            $triggers[] = ['assignee_changed', ['action' => 'assignee_changed', 'from' => $changes['assignee']['from'], 'to' => $changes['assignee']['to']]];
        }
        if (! $triggers) {
            return;
        }

        $issue = Issue::with('project')->find($activity->issue_id);
        if (! $issue) {
            return;
        }
        $actor = $activity->user_id ? User::find($activity->user_id) : null;
        // A person's decision on an agent's request is theirs, not the agent's.
        $agent = $activity->agent_id && ! isset($activity->changes['approval_request']) ? Agent::find($activity->agent_id) : null;
        // A watcher this comment mentioned gets the mention row only.
        $mentioned = isset($activity->changes['comment_id']) ? Mention::where('comment_id', $activity->changes['comment_id'])->pluck('mentioned_user_id')->all() : [];
        foreach ($this->watchers->watchers($issue)->reject(fn ($u) => $u->id === $activity->user_id) as $watcher) {
            foreach ($triggers as [$name, $change]) {
                if ($name === 'commented' && in_array($watcher->id, $mentioned, true)) {
                    continue;
                }
                $this->notifications->notify($watcher, $issue, $name, $change, $actor, $agent, $activity->via);
            }
        }
    }
}
