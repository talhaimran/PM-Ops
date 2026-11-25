<?php

namespace App\Listeners;

use App\Events\ActivityRecorded;
use App\Events\ProjectChanged;
use App\Events\ReactionChanged;

/** 3-R14: issue activity and reactions tell the project's live viewers (ProjectChanged, ids and keys only). */
class BroadcastChanges
{
    public function handleActivity(ActivityRecorded $event): void
    {
        ProjectChanged::about($event->activity->issue_id, $event->activity->action);
    }

    public function handleReaction(ReactionChanged $event): void
    {
        ProjectChanged::about($event->issueId, 'reaction');
    }
}
