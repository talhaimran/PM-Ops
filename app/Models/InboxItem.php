<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One line in a person's inbox (spec 7.10). The table is `notifications`, but the model isn't named Notification: User uses Laravel's
 * Notifiable trait, whose `notifications()` points at the same table. Never call $user->notifications().
 * shortcut: items are kept forever (3-R25); add a 90-day purge when the table grows.
 * `changes` is a list of {action, from, to}; `count` is how many changes were batched into the row (3-R6).
 */
#[Fillable(['workspace_id', 'user_id', 'issue_id', 'event', 'actor_user_id', 'agent_id', 'via', 'changes', 'count', 'read_at'])]
class InboxItem extends Model
{
    protected $table = 'notifications';

    protected function casts(): array
    {
        return ['changes' => 'array', 'count' => 'integer', 'read_at' => 'datetime'];
    }

    /**
     * $user's items in $workspaceId whose issue they can still see (deleted ones never). A daily summary has no issue of its own:
     * it shows while at least one issue id it names is still visible (JSON_TABLE over `changes`); NotificationService::annotate()
     * then lists only those.
     */
    public function scopeVisibleTo(Builder $query, User $user, int $workspaceId): void
    {
        $visible = fn () => Issue::visibleTo($user)->select('issues.id');
        $query->where('notifications.user_id', $user->id)->where('notifications.workspace_id', $workspaceId)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('notifications.issue_id')->where('notifications.event', '!=', 'agent_summary'))
                ->orWhereIn('notifications.issue_id', $visible())
                ->orWhere(fn ($q) => $q->where('notifications.event', 'agent_summary')->whereExists(
                    DB::query()->fromRaw("JSON_TABLE(notifications.changes, '$[*]' COLUMNS (issue_id BIGINT PATH '$.issue_id')) as named")->selectRaw('1')->whereIn('named.issue_id', $visible())
                )));
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
