<?php

namespace App\Models;

use App\Enums\AgentRunStatus;
use App\Services\CurrentWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Step B (B-R16): one in-app agent run on an issue: its steps, tokens and outcome. Never holds the AI key. */
#[Fillable(['workspace_id', 'issue_id', 'issue_key', 'user_id', 'agent_id', 'role_id', 'role_name', 'instruction', 'status', 'steps',
    'tokens_in', 'tokens_out', 'error', 'started_at', 'finished_at'])]
class AgentRun extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AgentRunStatus::class,
            'steps' => 'array',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function changes(): HasMany
    {
        return $this->hasMany(AgentRunChange::class, 'run_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AgentRole::class);
    }

    /** B-D6: only the person who started a run sees it, in the current workspace. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where('agent_runs.user_id', $user->id)->where('agent_runs.workspace_id', app(CurrentWorkspace::class)->id() ?? 0);
    }

    /** B-R9: tokens in and out of the workspace's runs created this calendar month (UTC, the app's timezone). */
    public static function monthTokens(Workspace $workspace): int
    {
        return (int) static::where('workspace_id', $workspace->id)->where('created_at', '>=', now()->utc()->startOfMonth())
            ->sum(DB::raw('tokens_in + tokens_out'));
    }
}
