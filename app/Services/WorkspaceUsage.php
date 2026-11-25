<?php

namespace App\Services;

use App\Enums\AgentStatus;
use App\Enums\Entitlement;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spec section 4 billing terms. Billed: workspace Owners and Admins, and anyone Member or Admin in a project.
 * Viewers: everyone else. Pending (unexpired) invitations count as the person they would create, once per address.
 */
class WorkspaceUsage
{
    public function billedUsers(Workspace $workspace): int
    {
        return count(array_filter($this->people($workspace)));
    }

    public function viewers(Workspace $workspace): int
    {
        return count($this->people($workspace)) - $this->billedUsers($workspace);
    }

    public function projects(Workspace $workspace): int
    {
        return $workspace->projects()->count();
    }

    /**
     * What Platform management bills from (spec 9; also every system event's snapshot). billed_users and viewers are
     * members; pending_billed_invitations are the extra billed people pending invitations would add.
     *
     * @return array{billed_users: int, viewers: int, pending_billed_invitations: int, projects: int, storage_mb: int}
     */
    public function snapshot(Workspace $workspace): array
    {
        $members = $this->people($workspace, withInvitations: false);
        $billed = count(array_filter($members));

        return [
            'billed_users' => $billed,
            'viewers' => count($members) - $billed,
            'pending_billed_invitations' => count(array_filter($this->people($workspace))) - $billed,
            'projects' => $this->projects($workspace),
            // 3-R20: attachments arrive in step 6.
            'storage_mb' => 0,
        ];
    }

    /** The admin API's usage (3-R20): the snapshot plus agents, API calls over 30 days and the last activity. */
    public function usage(Workspace $workspace): array
    {
        $agents = DB::table('agents')->where('workspace_id', $workspace->id)->where('status', '!=', AgentStatus::Revoked->value);
        $last = collect([
            DB::table('workspace_user')->where('workspace_id', $workspace->id)->max('last_active_at'),
            DB::table('activities')->join('issues', 'issues.id', '=', 'activities.issue_id')->join('projects', 'projects.id', '=', 'issues.project_id')
                ->where('projects.workspace_id', $workspace->id)->max('activities.created_at'),
            (clone $agents)->max('last_used_at'),
        ])->filter()->max();

        return [
            ...$this->snapshot($workspace),
            'agents' => $agents->count(),
            'api_calls_30d' => (int) DB::table('workspace_api_usage')->where('workspace_id', $workspace->id)->where('day', '>', now()->subDays(30)->toDateString())->sum('calls'),
            'last_activity_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
        ];
    }

    /** Whether $email counts as billed (true), as a Viewer (false), or not at all (null), pending invitations included. */
    public function isBilled(Workspace $workspace, string $email): ?bool
    {
        return $this->people($workspace)[strtolower($email)] ?? null;
    }

    /** The same, from membership alone: null when $email isn't a member. */
    public function memberBilled(Workspace $workspace, string $email): ?bool
    {
        return $this->people($workspace, withInvitations: false)[strtolower($email)] ?? null;
    }

    /** @return array<string, array{allowed: int, current: int}> the enforced limits this workspace is past */
    public function overLimit(Workspace $workspace): array
    {
        $entitlements = app(Entitlements::class);
        $over = [];
        foreach ([Entitlement::BilledUsers, Entitlement::Viewers, Entitlement::Projects] as $key) {
            $allowed = $entitlements->get($workspace, $key);
            $current = $this->current($workspace, $key);
            if ($allowed !== null && $current > $allowed) {
                $over[$key->value] = ['allowed' => $allowed, 'current' => $current];
            }
        }

        return $over;
    }

    public function current(Workspace $workspace, Entitlement $key): int
    {
        return match ($key) {
            Entitlement::BilledUsers => $this->billedUsers($workspace),
            Entitlement::Viewers => $this->viewers($workspace),
            Entitlement::Projects => $this->projects($workspace),
        };
    }

    /**
     * @return array<string, bool> lower-case email => billed
     *
     * shortcut: loads every member and invitation of the workspace (three queries); move to SQL if workspaces grow past a few thousand people.
     */
    public function people(Workspace $workspace, bool $withInvitations = true): array
    {
        $billedProjectRoles = [ProjectRole::Member->value, ProjectRole::Admin->value];
        $inBilledProject = DB::table('project_user')->join('projects', 'projects.id', '=', 'project_user.project_id')
            ->where('projects.workspace_id', $workspace->id)->whereIn('project_user.role', $billedProjectRoles)
            ->distinct()->pluck('project_user.user_id')->flip();

        $people = [];
        foreach ($workspace->members()->get(['users.id', 'users.email']) as $member) {
            $people[strtolower($member->email)] = $member->pivot->role !== WorkspaceRole::Member->value || $inBilledProject->has($member->id);
        }
        if (! $withInvitations) {
            return $people;
        }

        // Only pending ones: an expired invitation can't be accepted until it is resent, which checks again.
        foreach ($workspace->invitations()->where('expires_at', '>', now())->get(['email', 'role', 'project_role']) as $invitation) {
            $email = strtolower($invitation->email);
            // Accepting keeps a member's workspace role, so only the project role can make them billed.
            $wouldBe = in_array($invitation->project_role?->value, $billedProjectRoles, true)
                || (! isset($people[$email]) && $invitation->role === WorkspaceRole::Admin);
            $people[$email] = ($people[$email] ?? false) || $wouldBe;
        }

        return $people;
    }
}
