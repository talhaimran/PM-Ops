<?php

namespace App\Listeners;

use App\Events\ActivityRecorded;
use App\Events\ProjectChanged;
use App\Events\ReactionChanged;
use Throwable;

/** 3-R14: issue activity and reactions tell the project's live viewers (ProjectChanged, ids and keys only). */
class BroadcastChanges
{
    public function handleActivity(ActivityRecorded $event): void
    {
        $this->send($event->activity->issue_id, $event->activity->action);
    }

    public function handleReaction(ReactionChanged $event): void
    {
        $this->send($event->issueId, 'reaction');
    }

    /** Live updates are a nicety: a broker failure is reported and never fails the committed write. */
    private function send(int $issueId, string $action): void
    {
        try {
            ProjectChanged::about($issueId, $action);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
