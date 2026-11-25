<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** 3-R1: a reaction was added or removed (ids only; reactions aren't in the activity log). Nothing listens yet. */
class ReactionChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public int $commentId, public int $issueId, public int $projectId) {}
}
