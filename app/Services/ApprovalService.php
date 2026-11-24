<?php

namespace App\Services;

use App\Enums\AgentStatus;
use App\Enums\ApprovalStatus;
use App\Enums\ProjectRole;
use App\Enums\Resolution;
use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\InboxItem;
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
    public const UPDATE_RULES = ['cursor' => ['nullable', 'string', 'max:100'], 'since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']];

    public function __construct(private CurrentWorkspace $current, private ActivityLogger $activity, private NotificationService $notifications) {}

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
     * The resolution and the original's key (spec 7.4) ride along in the action, only when the agent sent them;
     * asking again replaces them with what the new ask sent.
     */
    public function requestDone(Issue $issue, User $owner, Agent $agent, Status $to, ?string $reason, ?Resolution $resolution = null, ?string $duplicateOf = null): ApprovalRequest
    {
        return DB::transaction(function () use ($issue, $owner, $agent, $to, $reason, $resolution, $duplicateOf) {
            // issue_version (spec 7.11): the version the agent's change left the issue at; approve() compares it.
            $resolved = [...array_filter(['resolution' => $resolution?->value, 'duplicate_of' => $duplicateOf]), 'issue_version' => $issue->version];
            Issue::whereKey($issue->id)->lockForUpdate()->first();
            // A locking read sees requests committed after this transaction's snapshot; SKIP LOCKED never waits on a
            // request a person is deciding (they hold it and then wait for the project row this move may hold).
            $open = ApprovalRequest::open()->where('issue_id', $issue->id)->where('action->to_status_id', $to->id)
                ->lock('for update skip locked')->first();

            // Asking again is the agent changing its mind: the person decides on the latest ask, so what it sent now
            // (maybe nothing, which approves as Done) replaces the old resolution and duplicate_of whole. The first
            // ask's version stays, so a person's change since still needs the second confirm (scan F1, PR 34).
            // Only the agent that opened the request can do that; another agent's repeat gets the existing request back (spec 8, A2).
            if ($open?->agent_id === $agent->id) {
                $action = [...Arr::except($open->action, ['resolution', 'duplicate_of']), ...Arr::except($resolved, ['issue_version'])];
                if ($action != $open->action) {
                    $open->update(['action' => $action]);
                }
            }
            $new = $open === null;
            $approval = $open ?? ApprovalRequest::create([
                'workspace_id' => $issue->project->workspace_id,
                'issue_id' => $issue->id,
                'agent_id' => $agent->id,
                'owner_user_id' => $owner->id,
                'action' => ['to_status_id' => $to->id, 'to_status' => $to->name, 'from_status_id' => $issue->status_id, 'reason' => $reason, ...$resolved],
                'status' => ApprovalStatus::Pending,
                'expires_at' => now()->addDays(ApprovalRequest::DAYS),
            ]);

            // 3-R5: the owner hears at once (never batched, never the project's Admins), once the request is committed.
            if ($new) {
                DB::afterCommit(fn () => $this->notifications->notify($owner, $issue, 'approval_request', ['action' => 'approval_request', 'from' => null, 'to' => $to->name], null, $agent, ActivityLogger::via($owner), batch: false));
            }

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
            // The original a Duplicate request names, found as the agent's owner (it was checked when the agent asked):
            // the decider may not see it, and must neither be blocked by it nor shown its key (2-D2).
            $duplicateOf = $approval->action['duplicate_of'] ?? null;
            $original = $duplicateOf ? app(IssueService::class)->peek(User::findOrFail($approval->owner_user_id), $duplicateOf) : null;

            $stale = match (true) {
                $issue->trashed() => "{$issue->key} was deleted.",
                $duplicateOf && ! $original => "The issue {$issue->key} would duplicate is no longer available.",
                ! $status => "{$approval->action['to_status']} is no longer a status in {$project->key}.",
                $approval->agent->status === AgentStatus::Revoked => self::revokedReason($approval->agent),
                $issue->status_id === $status->id => "{$issue->key} is already in {$status->name}.",
                default => null,
            };
            if ($stale) {
                $approval->update(['status' => ApprovalStatus::Stale, 'note' => $stale]);

                return $approval;
            }

            // Spec 7.11: any change since the request (not only a move) needs a second confirm, saying what changed.
            $asked = $approval->action['issue_version'] ?? 1;
            if ($issue->version !== $asked && ! $confirmChanged) {
                $changed = ActivityLogger::describe($this->activity->changesSince($issue, $asked, $decider), $issue->key);
                throw ValidationException::withMessages([
                    'approval' => "{$changed} since this request. Approve again to move it to {$status->name}.",
                ])->status(409);
            }

            ActivityLogger::forApproval($approval, fn () => app(BoardService::class)->move($issue, $decider, ['status' => $status->name, ...Arr::only($approval->action, ['resolution']), ...($original ? ['duplicate_of' => $original] : [])], 'approved'));
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
     * A-L3 and 3-R8: one stream, oldest first, of how $agent's requests ended (approved, rejected, expired, stale) and of
     * $user's notifications (changes to watched issues, mentions; not the daily summary or their own agent's requests),
     * after `cursor` (a position, not a page: pass back the last next_cursor to poll) or, with no cursor, since `$since`.
     * Approval outcomes are only that agent's own requests, in projects its owner can still reach; notifications only
     * for issues $user can still see. A request's last change is its end, so updated_at is when it happened; ties break
     * by kind (approvals first) and id. Reading marks nothing read.
     * Only complete seconds are read (before this second), so an item can't land behind a cursor: it shows up at most ~1s late, never skipped.
     * shortcut: a notification that batches a later change keeps its place (created_at), the new change isn't re-sent; use updated_at if agents need that.
     *
     * @return array{updates: list<array>, next_cursor: ?string}
     */
    public function updatesFor(User $user, ?Agent $agent, ?string $cursor, int $perPage = 25, ?string $since = null): array
    {
        [$at, $id, $notified] = $cursor ? $this->decodeUpdateCursor($cursor) : [$since ? Carbon::parse($since) : null, 0, false];
        // Items at the cursor's own second: those of the same kind after its id, all of a later kind, none of an earlier one (approvals come first).
        $after = fn ($q, string $column, string $idColumn, string $tie) => $q->when($at, fn ($q) => $q->where(fn ($q) => $q->where($column, $cursor ? '>' : '>=', $at)
            ->when($cursor && $tie !== 'none', fn ($q) => $q->orWhere(fn ($q) => $q->where($column, $at)->when($tie === 'id', fn ($q) => $q->where($idColumn, '>', $id))))));
        $ended = collect([ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Expired, ApprovalStatus::Stale])->map->value->all();

        $approvals = ! $agent ? collect() : ApprovalRequest::where('agent_id', $agent->id)->whereIn('status', $ended)->where('updated_at', '<', now()->startOfSecond())
            ->whereIn('issue_id', Issue::withTrashed()->whereIn('project_id', $user->accessibleProjects()->select('projects.id'))->select('issues.id'))
            ->tap(fn ($q) => $after($q, 'updated_at', 'id', $notified ? 'none' : 'id'))
            ->with(['issue' => fn ($q) => $q->withTrashed(), 'issue.project', 'decider:id,name'])
            ->orderBy('updated_at')->orderBy('id')->limit($perPage)->get()
            ->map(fn (ApprovalRequest $a) => ['rank' => 0, 'ts' => $a->updated_at->timestamp, 'id' => $a->id, 'item' => [
                'id' => "approval:{$a->id}",
                'kind' => "approval.{$a->status->value}",
                'approval_request_id' => $a->id,
                'issue' => $a->issue->key,
                'to' => $a->action['to_status'],
                'decided_by' => $a->decider?->name,
                'note' => $a->note,
                'at' => $a->updated_at->toIso8601String(),
            ]]);

        $workspace = $this->current->get();
        $notes = InboxItem::visibleTo($user, $workspace->id)->whereNotIn('notifications.event', ['agent_summary', 'approval_request'])
            ->where('notifications.created_at', '<', now()->startOfSecond())
            ->tap(fn ($q) => $after($q, 'notifications.created_at', 'notifications.id', $notified ? 'id' : 'all'))
            ->with(['issue.project', 'actor', 'agent.owner'])
            ->orderBy('notifications.created_at')->orderBy('notifications.id')->limit($perPage)->get()
            ->map(fn (InboxItem $n) => ['rank' => 1, 'ts' => $n->created_at->timestamp, 'id' => $n->id, 'item' => [
                'id' => "notification:{$n->id}",
                'kind' => "notification.{$n->event}",
                'notification_id' => $n->id,
                'issue' => $n->issue?->key,
                'text' => NotificationService::describe($n),
                'by' => $n->agent ? ActivityLogger::agentLabel($n->agent) : $n->actor?->name,
                'count' => $n->count,
                'at' => $n->created_at->toIso8601String(),
            ]]);

        $rows = $approvals->concat($notes)->sortBy([['ts', 'asc'], ['rank', 'asc'], ['id', 'asc']])->take($perPage)->values();
        $last = $rows->last();

        return [
            'updates' => $rows->pluck('item')->all(),
            'next_cursor' => ! $last ? $cursor : rtrim(strtr(base64_encode($last['ts'].'.'.$last['id'].($last['rank'] ? '.n' : '')), '+/', '-_'), '='),
        ];
    }

    /** @return array{0: Carbon, 1: int, 2: bool} when, id, and whether the id is a notification's (else an approval request's) */
    private function decodeUpdateCursor(string $cursor): array
    {
        $parts = explode('.', (string) base64_decode(strtr($cursor, '-_', '+/'), true));
        $notified = count($parts) === 3 && $parts[2] === 'n';
        if ((count($parts) !== 2 && ! $notified) || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1]) || strlen($parts[0]) > 10 || strlen($parts[1]) > 18 || (int) $parts[0] > now()->timestamp) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor. Pass the next_cursor from the previous get_updates response unchanged, or leave it out to start from the beginning.']);
        }

        return [Carbon::createFromTimestamp((int) $parts[0]), (int) $parts[1], $notified];
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
