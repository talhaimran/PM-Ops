<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['issue_id', 'user_id', 'body', 'agent_id'])]
class Comment extends Model
{
    use SoftDeletes;

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** Written by this user, and by the same hand: an agent's comment is its own, a person's typed one is theirs. */
    public function isWrittenBy(User $user): bool
    {
        return $this->user_id === $user->id && $this->agent_id === $user->agent()?->id;
    }
}
