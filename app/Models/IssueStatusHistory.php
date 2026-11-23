<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Spec 7.13: append-only report history (no updated_at); written only through HistoryRecorder. */
#[Fillable(['issue_id', 'from_status_id', 'to_status_id', 'to_category', 'user_id', 'agent_id', 'via'])]
class IssueStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'issue_status_history';
}
