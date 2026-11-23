<?php

namespace App\Services;

use App\Enums\AgentStatus;
use App\Enums\ApprovalStatus;
use App\Enums\ProjectRole;
use App\Enums\Resolution;
use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Spec 8 (A2): an agent can't move an issue to Done on its own. Its attempt becomes an approval request that the
 * agent's owner or a project Admin decides. "Done" is any Done-category status (spec 7.4).
 *
 * Locks: deciding takes the request row, then the project row, then the issue, the same order BoardService::move
 * uses after the request; an agent's attempt never waits on a request row.
 */
class ApprovalService
{
    /** Input for get_updates and GET /me/updates. */
    public const UPDATE_RULES = ['cursor' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']];

    public function __construct(private CurrentWorkspace $current, private ActivityLogger $activity) {}

    /** The one rule: an agent moving an issue into a Done-category status it isn't already in needs a person (1-R5). */
    public function needsApproval(Issue $issue, Status $to, ?Agent $agent): bool
    {
        return $agent !== null && $to->isDone() && $to->id !== $issue->status_id;
    }

    /** Why a revoked agent's request went stale; AgentService::revoke marks them at once. */
    public static function revokedReason(Agent $agent): string
    {
        return "{$agent->label()} was revoked.";
    }

    /**
     * Open a request for $agent's move of $issue to $to, or return the open one for the same issue and target.
     * The resolution and the original's key (spec 7.4) ride along in the action, only when the agent sent them.
     */
    public function requestDone(Issue $issue, User $owner, Agent $agent, Status $to, ?string $reason, ?Resolution $resolution = null, ?string $duplicateOf = null): ApprovalRequest
    {
        return DB::transaction(function () use ($issue, $owner, $agent, $to, $reason, $resolution, $duplicateOf) {
            $resolved = array_filter(['resolution' => $resolution?->value, 'duplicate_of' => $duplicateOf]);
            Issue::whereKey($issue->id)->lockForUpdate()->first();
            // A locking read sees requests committed after this transaction's snapshot; SKIP LOCKED never waits on a
            // request a person is deciding (they hold it and then wait for the project row this move may hold).
            $open = ApprovalRequest::open()->where('issue_id', $issue->id)->where('action->to_status_id', $to->id)
                ->lock('for update skip locked')->first();

            // The same ask again with another resolution is the agent changing its mind: the person decides on the latest.
            if ($open && $resolved) {
                $open->update(['action' => [...$open->action, ...$resolved]]);
            }
            $approval = $open ?? ApprovalRequest::create([
                'workspace_id' => $issue->project->workspace_id,
                'issue_id' => $issue->id,
                'agent_id' => $agent->id,
                'owner_user_id' => $owner->id,
                'action' => ['to_status_id' => $to->id, 'to_status' => $to->name, 'from_status_id' => $issue->status_id, 'reason' => $reason, ...$resolved],
                'status' => ApprovalStatus::Pending,
                'expires_at' => now()->addDays(ApprovalRequest::DAYS),
            ]);

            return $approval->setRelation('issue', $issue)->setRelation('agent', $agent->setRelation('owner', $owner));
        });
    }

    /**
     * Make the move as $decider, re-checking permissions and whether the issue moved since. A move that is no longer
     * possible marks the request stale; a moved issue needs $confirmChanged. Never moves silently.
     */
    public function approve(ApprovalRequest $approval, User $decider, bool $confirmChanged = false): ApprovalRequest
    {
        $approval = DB::transaction(function () use ($approval, $decider, $confirmChanged) {
            $approval = $this->lockPending($approval);
            if ($approval->status !== ApprovalStatus::Pending) {
                return $approval;
            }

            $projectId = Issue::withTrashed()->whereKey($approval->issue_id)->value('project_id');
            $project = Project::whereKey($projectId)->lockForUpdate()->firstOrFail();
            // 1-R2: the target must still be one of the project's statuses; the project lock holds column changes off.
            $status = $project->statuses()->where('statuses.id', $approval->action['to_status_id'])->first();
            $issue = Issue::withTrashed()->with('status')->whereKey($approval->issue_id)->lockForUpdate()->firstOrFail()->setRelation('project', $project);
            $this->authorize($approval->setRelation('issue', $issue), $decider);

            $stale = match (true) {
                $issue->trashed() => "{$issue->key} was deleted.",
                ! $status => "{$approval->action['to_status']} is no longer a status in {$project->key}.",
                $approval->agent->status === AgentStatus::Revoked => self::revokedReason($approval->agent),
                $issue->status_id === $status->id => "{$issue->key} is already in {$status->name}.",
                default => null,
            };
            if ($stale) {
                $approval->update(['status' => ApprovalStatus::Stale, 'note' => $stale]);

                return $approval;
            }

            // shortcut: "moved since" compares statuses; issue versions replace it (step 1, Task 4).
            if ($issue->status_id !== $approval->action['from_status_id'] && ! $confirmChanged) {
                $from = Status::whereKey($approval->action['from_status_id'])->value('name') ?? 'a deleted status';
                throw ValidationException::withMessages([
                    'approval' => "{$issue->key} moved from {$from} to {$issue->status->name} since this request. Approve again to move it to {$status->name}.",
                ])->status(409);
            }

            ActivityLogger::forApproval($approval, fn () => app(BoardService::class)->move($issue, $decider, ['status' => $status->name, ...Arr::only($approval->action, ['resolution', 'duplicate_of'])], 'approved'));
            $approval->update(['status' => ApprovalStatus::Approved, 'decided_by' => $decider->id, 'decided_at' => now()]);

            return $approval;
        });

        return $this->settled($approval);
    }

    /** Turn the request down; the issue stays where it is. */
    public function reject(ApprovalRequest $approval, User $decider, ?string $note): ApprovalRequest
    {
        $approval = DB::transaction(function () use ($approval, $decider, $note) {
            $approval = $this->lockPending($approval);
            if ($approval->status !== ApprovalStatus::Pending) {
                return $approval;
            }

            $issue = Issue::withTrashed()->with('project')->whereKey($approval->issue_id)->firstOrFail();
            $this->authorize($approval->setRelation('issue', $issue), $decider);
            $approval->update(['status' => ApprovalStatus::Rejected, 'decided_by' => $decider->id, 'decided_at' => now(), 'note' => $note]);
            ActivityLogger::forApproval($approval, fn () => $this->activity->log($issue, $decider, 'rejected'));

            return $approval;
        });

        return $this->settled($approval);
    }

    /**
     * Open requests $viewer can decide in $workspace: their own agents' requests in projects where they can still
     * make the move, and every request in projects they administer.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public function decidableBy(User $viewer, Workspace $workspace): Collection
    {
        $roles = DB::table('project_user')->where('user_id', $viewer->id)->pluck('role', 'project_id');
        $admin = $roles->filter(fn ($r) => $r === ProjectRole::Admin->value)->keys();
        $writer = $roles->filter(fn ($r) => ProjectRole::from($r)->canWrite())->keys();

        // shortcut: the 50 oldest; page it when the Pending approvals widget arrives (step 10).
        return ApprovalRequest::open()
            ->where('workspace_id', $workspace->id)
            ->where(fn ($q) => $q
                ->whereHas('issue', fn ($i) => $i->whereIn('project_id', $admin))
                ->orWhere(fn ($q) => $q->where('owner_user_id', $viewer->id)->whereHas('issue', fn ($i) => $i->whereIn('project_id', $writer))))
            ->with(['issue.project', 'issue.status', 'agent.owner'])
            ->oldest('id')
            ->limit(50)
            ->get();
    }

    /** A request in the current workspace on an issue $user can see; anything else is "not found". */
    public function resolve(User $user, int $id): ApprovalRequest
    {
        $approval = ApprovalRequest::whereKey($id)
            ->where('workspace_id', $this->current->id() ?? 0)
            ->whereHas('issue', fn ($q) => $q->withTrashed()->whereIn('project_id', $user->accessibleProjects()->select('projects.id')))
            ->first();

        if (! $approval) {
            throw ValidationException::withMessages([
                'approval' => "Approval request {$id} was not found. Open your dashboard to see the requests you can decide.",
            ])->status(404);
        }

        return $approval;
    }

    /**
     * A-L3: how $agent's requests ended, oldest first, after `cursor` (a position, not a page: pass back the last
     * next_cursor to poll). Only that agent's own requests, in projects its owner can still reach. A request's last
     * change is its end, so updated_at is when it happened; ties break by id.
     * Only complete seconds are read (updated_at before this second), so an outcome can't land behind a cursor: it shows up at most ~1s late, never skipped.
     *
     * @return array{updates: list<array>, next_cursor: ?string}
     */
    public function updatesFor(User $user, ?Agent $agent, ?string $cursor, int $perPage = 25): array
    {
        if (! $agent) {
            return ['updates' => [], 'next_cursor' => $cursor];
        }
        [$at, $id] = $cursor ? $this->decodeUpdateCursor($cursor) : [null, 0];
        $ended = collect([ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Expired, ApprovalStatus::Stale])->map->value->all();

        $rows = ApprovalRequest::where('agent_id', $agent->id)->whereIn('status', $ended)->where('updated_at', '<', now()->startOfSecond())
            ->whereIn('issue_id', Issue::withTrashed()->whereIn('project_id', $user->accessibleProjects()->select('projects.id'))->select('issues.id'))
            ->when($at, fn ($q) => $q->where(fn ($q) => $q->where('updated_at', '>', $at)->orWhere(fn ($q) => $q->where('updated_at', $at)->where('id', '>', $id))))
            ->with(['issue' => fn ($q) => $q->withTrashed(), 'issue.project', 'decider:id,name'])
            ->orderBy('updated_at')->orderBy('id')->limit($perPage)->get();

        return [
            'updates' => $rows->map(fn (ApprovalRequest $a) => [
                'id' => "approval:{$a->id}",
                'kind' => "approval.{$a->status->value}",
                'approval_request_id' => $a->id,
                'issue' => $a->issue->key,
                'to' => $a->action['to_status'],
                'decided_by' => $a->decider?->name,
                'note' => $a->note,
                'at' => $a->updated_at->toIso8601String(),
            ])->all(),
            'next_cursor' => $rows->isEmpty() ? $cursor : rtrim(strtr(base64_encode($rows->last()->updated_at->timestamp.'.'.$rows->last()->id), '+/', '-_'), '='),
        ];
    }

    /** @return array{0: Carbon, 1: int} */
    private function decodeUpdateCursor(string $cursor): array
    {
        $parts = explode('.', (string) base64_decode(strtr($cursor, '-_', '+/'), true));
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1]) || strlen($parts[0]) > 10 || strlen($parts[1]) > 18 || (int) $parts[0] > now()->timestamp) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor. Pass the next_cursor from the previous get_updates response unchanged, or leave it out to start from the beginning.']);
        }

        return [Carbon::createFromTimestamp((int) $parts[0]), (int) $parts[1]];
    }

    /** Mark pending requests past their expiry as expired; the agent can ask again. */
    public function expireDue(): int
    {
        return ApprovalRequest::where('status', ApprovalStatus::Pending)->where('expires_at', '<=', now())
            ->update(['status' => ApprovalStatus::Expired, 'updated_at' => now()]);
    }

    /** The request row locked; a pending one past its expiry is marked expired here. */
    private function lockPending(ApprovalRequest $approval): ApprovalRequest
    {
        $approval = ApprovalRequest::with('agent.owner')->whereKey($approval->id)->lockForUpdate()->firstOrFail();

        if ($approval->status !== ApprovalStatus::Pending) {
            throw ValidationException::withMessages(['approval' => "This request was already {$approval->status->value}."])->status(409);
        }
        if ($approval->expires_at->isPast()) {
            $approval->update(['status' => ApprovalStatus::Expired]);
        }

        return $approval;
    }

    private function authorize(ApprovalRequest $approval, User $decider): void
    {
        Gate::forUser($decider)->authorize('decide', $approval);
    }

    /** Expired or stale requests were saved as such; tell the decider why nothing moved. */
    private function settled(ApprovalRequest $approval): ApprovalRequest
    {
        $message = match ($approval->status) {
            ApprovalStatus::Expired => 'This request was already expired.',
            ApprovalStatus::Stale => "Can't approve: {$approval->note} The request is now stale.",
            default => null,
        };

        if ($message) {
            throw ValidationException::withMessages(['approval' => $message])->status(409);
        }

        return $approval;
    }
}
