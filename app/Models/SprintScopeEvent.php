<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Spec 7.13: append-only report history (no updated_at); written only through HistoryRecorder. */
#[Fillable(['sprint_id', 'issue_id', 'event', 'estimate', 'user_id', 'agent_id', 'via'])]
class SprintScopeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'sprint_scope_events';
}
