<?php

namespace App\Events;

use App\Models\AuditLog;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** 3-R1: an audit row was written (AuditLogger). Dispatched after commit, so a rolled-back change emits nothing. */
class AuditRecorded implements ShouldDispatchAfterCommit
{
    public function __construct(public AuditLog $log) {}
}
