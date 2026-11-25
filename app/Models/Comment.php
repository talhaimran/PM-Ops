<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['issue_id', 'user_id', 'body', 'agent_id'])]
class Comment extends Model
{
    use SoftDeletes;

    /** Spec 7.11: the version a new comment starts at (the column default), so a created model carries it too. */
    protected $attributes = ['version' => 1];

    /** Set by CommentService::add: what the writer should know (MentionService warnings). Not stored. */
    public array $warnings = [];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class)->orderBy('id');
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
