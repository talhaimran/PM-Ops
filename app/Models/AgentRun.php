<?php

namespace App\Models;

use App\Enums\AgentRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
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

    /** B-R9: tokens in and out of the workspace's runs created this calendar month (UTC, the app's timezone). */
    public static function monthTokens(Workspace $workspace): int
    {
        return (int) static::where('workspace_id', $workspace->id)->where('created_at', '>=', now()->utc()->startOfMonth())
            ->sum(DB::raw('tokens_in + tokens_out'));
    }
}
