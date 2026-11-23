<?php

namespace App\Services;

use App\Models\EstimateHistory;
use App\Models\Issue;
use App\Models\IssueStatusHistory;
use App\Models\Sprint;
use App\Models\SprintScopeEvent;
use App\Models\Status;
use App\Models\User;

/** Spec 7.13: one row per real change of status, sprint or estimate, attributed like the activity log. Call inside the change's transaction. */
class HistoryRecorder
{
    public function statusChanged(Issue $issue, ?Status $from, Status $to, ?User $actor): void
    {
        if ($from?->id === $to->id) {
            return;
        }
        IssueStatusHistory::create([
            'issue_id' => $issue->id, 'from_status_id' => $from?->id, 'to_status_id' => $to->id, 'to_category' => $to->category->value,
            ...ActivityLogger::attribution($actor),
        ]);
    }

    /** Left $from and/or joined $to, each with the issue's estimate now. */
    public function sprintChanged(Issue $issue, ?Sprint $from, ?Sprint $to, ?User $actor): void
    {
        if ($from?->id === $to?->id) {
            return;
        }
        foreach (['left' => $from, 'joined' => $to] as $event => $sprint) {
            if ($sprint) {
                SprintScopeEvent::create(['sprint_id' => $sprint->id, 'issue_id' => $issue->id, 'event' => $event, 'estimate' => $issue->story_points, ...ActivityLogger::attribution($actor)]);
            }
        }
    }

    public function estimateChanged(Issue $issue, ?int $from, ?int $to, ?User $actor): void
    {
        if ($from === $to) {
            return;
        }
        EstimateHistory::create(['issue_id' => $issue->id, 'from_estimate' => $from, 'to_estimate' => $to, ...ActivityLogger::attribution($actor)]);
    }
}
