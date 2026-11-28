<?php

namespace App\Services;

use App\Events\NotificationsChanged;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\InboxItem;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Spec 7.10 (3-R4..R8): the inbox. notify() writes (batching a person's or agent's quick run of changes into one row),
 * inbox() / unreadCount() read only what the reader can still see, summary() is the daily note about a person's agents.
 * Mentions and approval requests are never batched. Web only: nothing here is an API or MCP input.
 */
class NotificationService
{
    /** Events that always get their own row: they are never joined, and never join another row. */
    private const OWN_ROW = ['mention', 'approval_request', 'agent_summary', 'agent_run.finished', 'agent_run.failed'];

    /** Most issue keys a summary shows (its text says how many in all). shortcut: first 50 keys, page them if owners ask. */
    public const SUMMARY_KEYS = 50;

    public function __construct(private CurrentWorkspace $current) {}

    /**
     * Tell $to about one change to $issue. Never about their own change ($actor is $to). With $batch, the change joins $to's
     * newest unread row for the issue if the same actor made it less than pm.notification_batch_minutes ago.
     * shortcut: the lock is on that newest unread row, so two first changes racing (no row yet) can still make two rows; lock the issue_watchers row if that shows.
     *
     * @param  array{action: string, from: mixed, to: mixed}  $change
     */
    public function notify(User $to, Issue $issue, string $event, array $change, ?User $actor, ?Agent $agent, string $via, bool $batch = true): ?InboxItem
    {
        if ($actor?->id === $to->id) {
            return null;
        }
        $workspaceId = $issue->project->workspace_id;

        return DB::transaction(function () use ($to, $issue, $event, $change, $actor, $agent, $via, $batch, $workspaceId) {
            // Sent after commit, so the bell reloads once the row is there.
            event(new NotificationsChanged($to->id));
            if ($batch && ! in_array($event, self::OWN_ROW, true)) {
                $row = InboxItem::where('user_id', $to->id)->where('workspace_id', $workspaceId)->where('issue_id', $issue->id)
                    ->whereNull('read_at')->whereNotIn('event', self::OWN_ROW)->orderByDesc('id')->lockForUpdate()->first();
                if ($row && $row->actor_user_id === $actor?->id && $row->agent_id === $agent?->id
                    && $row->created_at->gte(now()->subMinutes((int) config('pm.notification_batch_minutes')))) {
                    $row->update(['changes' => [...$row->changes, $change], 'count' => $row->count + 1]);

                    return $row;
                }
            }

            return InboxItem::create([
                'workspace_id' => $workspaceId, 'user_id' => $to->id, 'issue_id' => $issue->id, 'event' => $event,
                'actor_user_id' => $actor?->id, 'agent_id' => $agent?->id, 'via' => $via, 'changes' => [$change],
                // B-R1: whatever an in-app run causes names it.
                'agent_run_id' => ActivityLogger::run()?->id,
            ]);
        }, 3); // Two first changes for one recipient can deadlock on the empty-range lock above; InnoDB kills one, so retry it.
    }

    /** $user's items in $workspace they can still see, newest first. Needs $workspace to be the current one. */
    public function inbox(User $user, Workspace $workspace, int $perPage = 25): LengthAwarePaginator
    {
        $page = InboxItem::visibleTo($user, $workspace->id)->with(['issue.project', 'actor', 'agent.owner'])->orderByDesc('notifications.id')->paginate($perPage);
        $this->annotate($page->getCollection(), $user);

        return $page;
    }

    public function unreadCount(User $user, Workspace $workspace): int
    {
        return InboxItem::visibleTo($user, $workspace->id)->whereNull('notifications.read_at')->count();
    }

    /**
     * Give each daily summary in $items `summary_keys`: the keys, looked up now, of the issues it names that $user can still see
     * (a renamed project shows its new key, a lost one is gone). One query for the whole page.
     *
     * @param  iterable<InboxItem>  $items
     */
    public function annotate(iterable $items, User $user): void
    {
        $summaries = collect($items)->where('event', 'agent_summary');
        $ids = $summaries->flatMap(fn (InboxItem $i) => collect($i->changes)->pluck('issue_id')->filter())->unique()->all();
        $keys = $ids ? Issue::visibleTo($user)->whereIn('issues.id', $ids)->with('project')->get()->mapWithKeys(fn (Issue $i) => [$i->id => $i->key]) : collect();
        foreach ($summaries as $item) {
            $item->setAttribute('summary_keys', collect($item->changes)->pluck('issue_id')->filter()->map(fn ($id) => $keys[$id] ?? null)->filter()->values()->all());
        }
    }

    /** One of $user's own items in the current workspace, or the 404 every missing id gets. */
    public function resolve(User $user, int $id): InboxItem
    {
        $item = InboxItem::where('user_id', $user->id)->where('workspace_id', $this->current->id() ?? 0)->whereKey($id)->first();
        if (! $item) {
            throw ValidationException::withMessages(['notification' => "Notification {$id} was not found. Open your inbox to see yours."])->status(404);
        }

        return $item;
    }

    /** Mark $user's unread items read: those in $ids, or with 'all' every one in the current workspace. Returns how many changed. */
    public function markRead(User $user, int|array|string $ids): int
    {
        $changed = InboxItem::where('user_id', $user->id)->where('workspace_id', $this->current->id() ?? 0)->whereNull('read_at')
            ->when($ids !== 'all', fn ($q) => $q->whereIn('id', (array) $ids))
            // Not touching updated_at: reading a row mustn't make get_updates send it again.
            ->toBase()->update(['read_at' => now()]);
        // The bell in the reader's other tabs.
        $changed && event(new NotificationsChanged($user->id));

        return $changed;
    }

    /**
     * 3-R7: the one note for $owner about what their agents did on $day in $workspace, naming only issues they can see.
     * None when there is nothing to say; asking twice for a day returns the first.
     * shortcut: server-time day, upgrade when users get a time zone.
     */
    public function summary(Workspace $workspace, User $owner, Carbon $day): ?InboxItem
    {
        $date = $day->toDateString();

        // Check-then-insert under a lock per owner and day, so two schedulers can't both make the day's row.
        return Cache::lock("pm:agent-summary:{$workspace->id}:{$owner->id}:{$date}", 60)->block(5, fn () => $this->makeSummary($workspace, $owner, $day, $date));
    }

    private function makeSummary(Workspace $workspace, User $owner, Carbon $day, string $date): ?InboxItem
    {
        $existing = InboxItem::where('workspace_id', $workspace->id)->where('user_id', $owner->id)->where('event', 'agent_summary')->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(changes, '\$[0].to')) = ?", [$date])->first();
        if ($existing) {
            return $existing;
        }
        $agents = Agent::where('workspace_id', $workspace->id)->where('owner_user_id', $owner->id)->pluck('id');
        // A person's approval of an agent's request carries the agent too, but the person made that change.
        $touched = Activity::whereIn('agent_id', $agents)->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->whereNull('changes->approval_request')->distinct()->pluck('issue_id');
        if ($touched->isEmpty()) {
            return null;
        }

        // The owner's own view: no token project scope, and the workspace the ids are read in.
        $previous = $this->current->id() ? $this->current->get() : null;
        $this->current->set($workspace);
        try {
            $ids = Issue::visibleTo(User::findOrFail($owner->id))->whereIn('issues.id', $touched)->orderBy('issues.id')->pluck('issues.id');
        } finally {
            $previous ? $this->current->set($previous) : $this->current->forget();
        }
        if ($ids->isEmpty()) {
            return null;
        }

        return tap(InboxItem::create([
            'workspace_id' => $workspace->id, 'user_id' => $owner->id, 'event' => 'agent_summary', 'via' => 'system',
            // One agent: its id, so the text says "Your agent"; several: none, "Your agents".
            'agent_id' => $agents->count() === 1 ? $agents->first() : null,
            // Ids, not keys: what the reader sees is decided when they read it (annotate), so lost access hides the issue.
            'changes' => [['action' => 'day', 'from' => null, 'to' => $date], ...$ids->map(fn ($id) => ['action' => 'updated', 'from' => null, 'to' => null, 'issue_id' => $id])->all()],
            'count' => $ids->count(),
        ]), fn () => event(new NotificationsChanged($owner->id)));
    }

    /** The line an item shows: "Alex's agent moved WEB-12 to In Review". Needs issue.project, actor and agent.owner loaded. */
    public static function describe(InboxItem $item): string
    {
        $key = $item->issue?->key;
        $last = collect($item->changes)->last();
        if ($item->event === 'agent_summary') {
            $day = Carbon::parse($item->changes[0]['to']);
            $when = $day->isYesterday() ? 'yesterday' : 'on '.$day->format('M j');

            $n = count($item->summary_keys ?? []);

            return ($item->agent_id ? 'Your agent' : 'Your agents')." updated {$n} ".($n === 1 ? 'issue' : 'issues')." {$when}";
        }
        $who = $item->agent ? ActivityLogger::agentLabel($item->agent) : ($item->actor?->name ?? 'Someone');
        if ($item->count > 1) {
            return "{$who} made {$item->count} changes to {$key}";
        }

        return match ($item->event) {
            'commented' => "{$who} commented on {$key}",
            'status_changed' => "{$who} moved {$key} to {$last['to']}",
            'assignee_changed' => $last['to'] ? "{$who} assigned {$key} to {$last['to']}" : "{$who} unassigned {$key}",
            'resolved' => "{$who} resolved {$key} as ".str_replace('_', ' ', (string) $last['to']),
            'reopened' => "{$who} reopened {$key}",
            'approval_request' => "{$who} asked to move {$key} to {$last['to']}",
            'mention' => "{$who} mentioned you on {$key}",
            'agent_run.finished' => "{$who} finished its run on {$key}",
            'agent_run.failed' => "{$who}'s run on {$key} stopped: {$last['to']}",
            default => "{$who} changed {$key}",
        };
    }
}
