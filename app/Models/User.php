<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\WorkspaceRole;
use App\Services\ActivityLogger;
use App\Services\CurrentWorkspace;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\AccessToken as OAuthToken;
use Laravel\Passport\TransientToken as OAuthTransientToken;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

#[Fillable(['name', 'email', 'password', 'date_shift', 'my_issues_group', 'auto_watch'])]
// Hidden: auth.user is shared with every Inertia page.
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    private ?Agent $requestAgent = null;

    /** Spec 7.10: a new user auto-watches (the column default), so a created model carries it too. */
    protected $attributes = ['auto_watch' => true];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'auto_watch' => 'boolean',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('role')->withTimestamps();
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** False for someone who signed up with Google or GitHub and hasn't set a password (spec 7.0f). */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role', 'last_active_at')->withTimestamps();
    }

    public function workspaceRole(Workspace $workspace): ?WorkspaceRole
    {
        $role = $this->workspaces()->whereKey($workspace->id)->value('workspace_user.role');

        return $role ? WorkspaceRole::from($role) : null;
    }

    /**
     * Projects this request may touch in the current workspace: the user's own, or every one for a workspace Owner or
     * Admin (read-only until they join, spec 7.0b); narrowed to the agent's projects when it has any.
     * Each row carries my_role (null where not joined). No current workspace, no projects.
     * Use this, not projects(), for every lookup.
     */
    public function accessibleProjects(): Builder
    {
        $current = app(CurrentWorkspace::class);
        $seesAll = $current->id() && Gate::forUser($this)->allows('viewAllProjects', $current->get());

        return Project::query()
            ->select('projects.*')
            ->selectSub(DB::table('project_user')->select('role')->whereColumn('project_id', 'projects.id')->where('user_id', $this->id), 'my_role')
            ->where('projects.workspace_id', $current->id() ?? 0)
            ->unless($seesAll, fn ($q) => $q->whereHas('members', fn ($m) => $m->whereKey($this->id)))
            ->when($this->tokenProjectIds(), fn ($q, $ids) => $q->whereIn('projects.id', $ids))
            // A run sends what it reads to the AI provider: projects whose Admins turned Mode C off are out of its reach.
            ->when(ActivityLogger::run($this), fn ($q) => $q->whereNotExists(fn ($s) => $s->from('project_agent_settings')
                ->whereColumn('project_agent_settings.project_id', 'projects.id')->where('mode_c_enabled', false)));
    }

    /** The agent whose token made this request (spec 8, A-D1), or whose in-app run is working (B-R1); null for people. Cached for the token in use. */
    public function agent(): ?Agent
    {
        if ($run = ActivityLogger::run($this)) {
            return $run->agent;
        }
        $token = $this->currentAccessToken();
        $agentId = $token instanceof PersonalAccessToken || $token instanceof OAuthToken ? $token->agent_id : null;
        if (! $agentId) {
            return null;
        }
        if ($this->requestAgent?->id !== $agentId) {
            $this->requestAgent = Agent::find($agentId);
        }

        return $this->requestAgent;
    }

    /** The request came with a bearer token (an agent's Sanctum token, or an MCP client's OAuth token), not a session. */
    public function viaToken(): bool
    {
        // B-R1: an in-app run acts like the agent's token, so every agent rule applies to it.
        if (ActivityLogger::run($this)) {
            return true;
        }
        $token = $this->currentAccessToken();

        return $token instanceof PersonalAccessToken || $token instanceof OAuthToken || $token instanceof OAuthTransientToken;
    }

    /** @return list<int>|null project ids the current agent is limited to; null when unrestricted */
    public function tokenProjectIds(): ?array
    {
        $ids = $this->agent()?->projects->pluck('id')->all();

        return $ids ?: null;
    }

    /** People may write; an agent needs Write or Delete. A token with no agent never writes (it is refused anyway). */
    public function canWrite(): bool
    {
        return ! $this->viaToken() || (bool) $this->agent()?->permission_level->canWrite();
    }

    /** People may delete; an agent only at the Delete level. */
    public function canDelete(): bool
    {
        return ! $this->viaToken() || (bool) $this->agent()?->permission_level->canDelete();
    }
}
