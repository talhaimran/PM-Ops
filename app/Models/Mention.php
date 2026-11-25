<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One person @mentioned in one comment (or the description when comment_id is null). Written only by MentionService. */
#[Fillable(['issue_id', 'comment_id', 'mentioned_user_id', 'by_user_id', 'agent_id', 'via'])]
class Mention extends Model
{
    public const UPDATED_AT = null;
}
