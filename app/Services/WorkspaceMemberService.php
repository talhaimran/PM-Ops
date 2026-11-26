<?php

namespace App\Services;

use App\Enums\Entitlement;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Workspace roles and removal (spec 7.0b). Every change locks the workspace row and re-reads both roles, so two
 * Owners demoting or removing each other at the same moment can't leave the workspace without one.
 */
class WorkspaceMemberService
{
    public const LAST_OWNER = 'A workspace needs at least one Owner. Make someone else an Owner first.';

    public const OWNERS_ONLY = 'Only Owners can add, change or remove Owners.';

    public function __construct(private MembershipService $memberships, private AuditLogger $audit, private Entitlements $entitlements, private WorkspaceUsage $usage, private AgentService $agents) {}

    public function changeRole(Workspace $workspace, User $actor, User $member, WorkspaceRole $role): void
    {
        // Spec 7.0c: a Member becoming Admin (or Owner) needs a billed seat.
        $this->entitlements->locked($workspace, function () use ($workspace, $actor, $member, $role) {
            $now = $this->usage->isBilled($workspace, $member->email);
            $this->entitlements->ensurePerson($workspace, $now, $now === true || $role !== WorkspaceRole::Member);
            $current = $this->lockAndCheck($workspace, $actor, $member, $role === WorkspaceRole::Owner, 'role');
            $billedBefore = $this->usage->memberBilled($workspace, $member->email) === true;
            if ($current === WorkspaceRole::Owner && $role !== WorkspaceRole::Owner) {
                $this->ensureAnotherOwner($workspace, $member, 'role');
            }
            $workspace->members()->updateExistingPivot($member->id, ['role' => $role->value]);

            if ($current !== $role) {
                $action = match (WorkspaceRole::Owner) {
                    $role => 'owner.added',
                    $current => 'owner.removed',
                    default => 'member.role_changed',
                };
                // billed_before/after: member.role_changed's system event says whether the change flipped billing (3-R21).
                $this->audit->log($action, $member, [
                    'from' => $current->value, 'to' => $role->value,
                    ...($action === 'member.role_changed' ? ['billed_before' => $billedBefore, 'billed_after' => $this->usage->memberBilled($workspace, $member->email) === true] : []),
                ], $workspace, $actor);
            }
        });
    }

    /**
     * Takes $member out of the workspace and every project in it, and revokes their agents there. Anyone may leave; removing
     * someone else needs an Owner or Admin. $newAdmins (project key => email) names a new Admin for each project
     * $member is the only Admin of.
     *
     * @param  array<string, string>  $newAdmins
     */
    public function remove(Workspace $workspace, User $actor, User $member, array $newAdmins = []): void
    {
        $this->entitlements->locked($workspace, function () use ($workspace, $actor, $member, $newAdmins) {
            // Spec 7.0c: new project admins who are Viewers become billed; the removed member's seat (if billed) frees one.
            $newlyBilled = collect($newAdmins)->filter()->map(fn ($e) => strtolower(trim($e)))->unique()
                ->reject(fn ($e) => $e === strtolower($member->email) || $this->usage->isBilled($workspace, $e) !== false)->count();
            $adding = $newlyBilled - ($this->usage->isBilled($workspace, $member->email) ? 1 : 0);
            if ($adding > 0) {
                $this->entitlements->ensure($workspace, Entitlement::BilledUsers, $adding);
            }

            $role = $this->lockAndCheck($workspace, $actor, $member, false, 'member');
            if ($role === WorkspaceRole::Owner) {
                $this->ensureAnotherOwner($workspace, $member, 'member');
            }

            $keys = $this->memberships->soleAdminProjectKeys($member, $workspace);
            $missing = array_values(array_diff($keys, array_keys(array_filter($newAdmins))));
            if ($missing) {
                throw ValidationException::withMessages([
                    'new_admins' => "{$member->email} is the only admin of ".implode(', ', $missing).'. Pick a new admin for each first.',
                ]);
            }
            foreach ($keys as $key) {
                $admin = $workspace->members()->where('email', strtolower(trim($newAdmins[$key])))->first();
                if (! $admin || $admin->is($member)) {
                    throw ValidationException::withMessages([
                        "new_admins.{$key}" => "Pick a member of {$workspace->name} other than {$member->email} as the new admin of {$key}.",
                    ]);
                }
                $this->memberships->add($workspace->projects()->firstWhere('key', $key), $admin, ProjectRole::Admin);
            }

            $billed = $this->usage->memberBilled($workspace, $member->email) === true;
            $member->projects()->detach($workspace->projects()->pluck('id'));
            $this->agents->revokeAllOf($member, $workspace);
            $member->tokens()->where('workspace_id', $workspace->id)->delete();
            $workspace->members()->detach($member->id);
            $this->audit->log('member.removed', $member, [...array_filter(['role' => $role->value, 'new_admins' => $newAdmins ? array_intersect_key($newAdmins, array_flip($keys)) : null]), 'billed' => $billed], $workspace, $actor);
        });
    }

    /**
     * Account deletion leaves every workspace at once: revokes their agents and writes the member.removed (plus owner.removed for an
     * Owner) audit rows, with role and billed read before the rows cascade away, so Platform management's billing feed sees it (3-R21).
     * shortcut: the workspace rows aren't locked, so a concurrent role change can leave the row's role a moment stale; the caller checks sole Owners first.
     */
    public function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Lock their workspaces (id order) and re-check, so two Owners deleting at once can't both pass the page's check.
            $workspaces = Workspace::whereIn('id', $user->workspaces()->pluck('workspaces.id'))->orderBy('id')->lockForUpdate()->get();
            $this->ensureNotSoleOwner($user);
            foreach ($workspaces as $workspace) {
                $role = $workspace->members()->whereKey($user->id)->firstOrFail()->pivot->role;
                $billed = $this->usage->memberBilled($workspace, $user->email) === true;
                $this->agents->revokeAllOf($user, $workspace, 'owner_deleted');
                $this->audit->log('member.removed', $user, ['role' => $role, 'billed' => $billed], $workspace, $user);
                if ($role === WorkspaceRole::Owner->value) {
                    $this->audit->log('owner.removed', $user, ['from' => $role, 'to' => null], $workspace, $user);
                }
            }
            $user->delete();
        });
    }

    /** Spec 7.0b: a workspace Owner or Admin joins any project as its Admin (authorized by ProjectPolicy::join). */
    public function joinProjectAsAdmin(Project $project, User $actor): void
    {
        $this->memberships->add($project, $actor, ProjectRole::Admin);
        $this->audit->log('project.admin_joined', $project, [], $project->workspace()->first(), $actor);
    }

    /** E7: refuses while $user is the only Owner of a workspace. */
    public function ensureNotSoleOwner(User $user): void
    {
        if ($names = $this->soleOwnerOf($user)) {
            throw ValidationException::withMessages([
                'password' => 'You are the last Owner of '.implode(', ', $names).'. Make someone else an Owner or delete those workspaces first.',
            ]);
        }
    }

    /** E7: names of workspaces $user is the only Owner of; their account can't be deleted while this isn't empty. */
    public function soleOwnerOf(User $user): array
    {
        return $user->workspaces()->wherePivot('role', WorkspaceRole::Owner->value)
            ->withCount(['members as owner_count' => fn ($q) => $q->where('workspace_user.role', WorkspaceRole::Owner->value)])
            ->orderBy('name')->get()->where('owner_count', 1)->pluck('name')->all();
    }

    /** Locks the workspace, then checks the rules on fresh roles; returns $member's current role. */
    private function lockAndCheck(Workspace $workspace, User $actor, User $member, bool $makesOwner, string $field): WorkspaceRole
    {
        Workspace::whereKey($workspace->id)->lockForUpdate()->first();
        $actorRole = $actor->workspaceRole($workspace);
        $memberRole = $member->workspaceRole($workspace);

        $deny = fn (string $message, int $status = 422) => ValidationException::withMessages([$field => $message])->status($status);
        if (! $memberRole) {
            throw $deny("{$member->email} is not a member of {$workspace->name}.", 404);
        }
        $leaving = $field === 'member' && $actor->is($member);
        if (! $leaving && ! in_array($actorRole, [WorkspaceRole::Owner, WorkspaceRole::Admin], true)) {
            throw $deny("Only Owners and Admins of {$workspace->name} can do this.", 403);
        }
        if (! $leaving && ($makesOwner || $memberRole === WorkspaceRole::Owner) && $actorRole !== WorkspaceRole::Owner) {
            throw $deny(self::OWNERS_ONLY);
        }

        return $memberRole;
    }

    private function ensureAnotherOwner(Workspace $workspace, User $member, string $field): void
    {
        $others = $workspace->members()->wherePivot('role', WorkspaceRole::Owner->value)->whereKeyNot($member->id)->count();
        if ($others === 0) {
            throw ValidationException::withMessages([$field => self::LAST_OWNER]);
        }
    }
}
