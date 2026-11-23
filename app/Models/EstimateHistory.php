<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Spec 7.13: append-only report history (no updated_at); written only through HistoryRecorder. */
#[Fillable(['issue_id', 'from_estimate', 'to_estimate', 'user_id', 'agent_id', 'via'])]
class EstimateHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'estimate_history';
}
