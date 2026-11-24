<?php

namespace App\Events;

use App\Models\Activity;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** 3-R1: an issue activity row was written (ActivityLogger::log). Dispatched after commit, so a rolled-back change emits nothing. */
class ActivityRecorded implements ShouldDispatchAfterCommit
{
    public function __construct(public Activity $activity) {}
}
