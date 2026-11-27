<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'settings'])]
class Workspace extends Model
{
    /** Spec 7.0a defaults; sign_in_methods "all" means every method the install offers. */
    public const DEFAULT_SETTINGS = [
        'members_can_create_projects' => true, 'require_2fa' => false, 'agents_enabled' => true, 'mode_c_enabled' => true, 'sign_in_methods' => ['password', 'google', 'github'],
        // Step B: limits on one in-app agent run.
        'run_max_steps' => 15, 'run_token_budget' => 50000, 'run_timeout_seconds' => 180,
    ];

    /** E1: words a slug may not be, so /{slug} never shadows an app path. */
    public const RESERVED_SLUGS = [
        'login', 'logout', 'register', 'forgot-password', 'reset-password', 'verify-email', 'confirm-password',
        'two-factor-challenge', 'auth', 'profile', 'dashboard', 'projects', 'issues', 'comments', 'search',
        'settings', 'invitations', 'workspaces', 'api', 'mcp', 'docs', 'internal', 'up', 'build', 'storage', 'admin', 'new', 'support',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
            'deletion_scheduled_at' => 'datetime',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role', 'last_active_at')->withTimestamps();
    }

    public function owners(): BelongsToMany
    {
        return $this->members()->wherePivot('role', WorkspaceRole::Owner->value);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(WorkspaceEntitlement::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function setting(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    /** 3-R16: suspended (a notice) or archived (3-R17) workspaces take no writes from anyone. */
    public function isWritable(): bool
    {
        return ! $this->suspended_at && ! $this->archived_at;
    }

    /** active, suspended or archived (archived wins). */
    public function state(): string
    {
        return $this->archived_at ? 'archived' : ($this->suspended_at ? 'suspended' : 'active');
    }

    public function addMember(User $user, WorkspaceRole $role): void
    {
        $this->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }
}
