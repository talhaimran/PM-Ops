<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reaction by one person or agent. Written only by ReactionService; never touches the comment's updated_at or version. */
#[Fillable(['comment_id', 'user_id', 'agent_id', 'via', 'emoji', 'reactor'])]
class CommentReaction extends Model
{
    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
