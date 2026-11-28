<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['issue_id', 'user_id', 'action', 'changes', 'via', 'token_name', 'agent_id', 'agent_run_id', 'issue_version'])]
class Activity extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['changes' => 'array', 'issue_version' => 'integer'];
    }

    /** 2-D2: a link entry names the other issue, so it shows only to those who can see that issue (deleted or not). */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->where(fn ($q) => $q->whereNotIn('action', ['linked', 'unlinked'])
            ->orWhereIn('changes->link->issue_id', Issue::withTrashed()->visibleTo($viewer)->select('issues.id')));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
