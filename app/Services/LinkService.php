<?php

namespace App\Services;

use App\Enums\LinkType;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\Status;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Spec 7.5: issue links, shared by web, REST and MCP. Callers resolve both issues with IssueService::resolve (so an
 * issue the actor can't see is the usual 404) and authorize (IssuePolicy update / unlink) first.
 *
 * Locks (2-R4, owner review): link writes run in a short transaction that holds no issue row locks of its own. The
 * insert share-locks both issue rows through the foreign keys, so it never runs while this request holds another issue
 * row (IssueService defers the Duplicate link until after its commit). blocks and precedes links are added one at a
 * time per workspace under a MySQL named lock, taken before the transaction, so the loop check and the insert can't
 * interleave with another request's. One original per Duplicate is held by a unique index (duplicate_source_id).
 */
class LinkService
{
    public function __construct(private ActivityLogger $activity) {}

    /** Link $from to $to; a reverse name ("is blocked by") is stored the other way round. */
    public function link(Issue $from, string $type, Issue $to, User $actor): IssueLink
    {
        [$type, $source, $target] = $this->stored($from, $type, $to);

        if ($source->is($target)) {
            throw ValidationException::withMessages(['to' => "An issue can't be linked to itself."]);
        }
        // A duplicates link sets the source's original (and replaces its old one), so it changes the source issue: the caller
        // must be able to edit it even when they named it as the target ("is duplicated by"), scan F1.
        if ($type === LinkType::Duplicates) {
            Gate::forUser($actor)->authorize('update', $source);
        }
        if ($this->find($source, $type, $target)) {
            throw $this->exists($source, $type, $target);
        }
        if (! $type->forbidsLoops()) {
            return $this->insert($source, $type, $target, $actor);
        }

        // Strict loop prevention: one blocks/precedes link at a time per workspace, checked and inserted under the lock.
        $lock = "pm:links:{$source->project->workspace_id}";
        if ((int) DB::scalar('SELECT GET_LOCK(?, ?)', [$lock, config('pm.link_lock_timeout')]) !== 1) {
            throw ValidationException::withMessages(['type' => 'Links are being changed in this workspace; try again.'])->status(409);
        }
        try {
            return $this->insert($source, $type, $target, $actor);
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** The loop check, the replaced original (duplicates) and the insert, in one transaction. */
    private function insert(Issue $source, LinkType $type, Issue $target, User $actor, bool $retry = true): IssueLink
    {
        try {
            return DB::transaction(function () use ($source, $type, $target, $actor) {
                if ($type->forbidsLoops()) {
                    $this->refuseLoop($source, $type, $target, $actor);
                }
                if ($type === LinkType::Duplicates) {
                    // One original per Duplicate (Should 3): a new one replaces the old, logged on both pairs. A locking
                    // read, so a retry sees what a concurrent replacement committed.
                    $replaced = IssueLink::where('source_issue_id', $source->id)->where('type', $type)->lockForUpdate()
                        ->with(['source.project', 'target' => fn ($q) => $q->withTrashed()->with('project')])->get()
                        ->each(fn (IssueLink $old) => $this->remove($old, $actor));
                }
                $attribution = ActivityLogger::attribution($actor);
                $link = IssueLink::create([
                    'source_issue_id' => $source->id, 'target_issue_id' => $target->id, 'type' => $type,
                    'created_by' => $attribution['user_id'], 'agent_id' => $attribution['agent_id'], 'via' => $attribution['via'],
                ]);
                $this->logBoth('linked', $source, $type, $target, $actor);
                // For the web Undo, which puts it back (restoreOriginal).
                $link->replaced = ($replaced ?? null)?->first()?->target;

                return $link->setRelation('source', $source)->setRelation('target', $target);
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($retry && $type === LinkType::Duplicates && str_contains($e->getMessage(), 'duplicate_source_id')) {
                // Another request set this issue's original at the same instant: replace it again, so the last writer wins.
                return $this->insert($source, $type, $target, $actor, retry: false);
            }
            // The same link, sent twice at the same instant.
            throw $this->exists($source, $type, $target);
        }
    }

    /** Remove the link between $a and $b ($type may be the reverse name, as for link()); returns it, as it was stored. */
    public function unlink(Issue $a, string $type, Issue $b, User $actor): IssueLink
    {
        [$type, $source, $target] = $this->stored($a, $type, $b);
        $link = $this->find($source, $type, $target)
            ?? throw ValidationException::withMessages(['type' => "No link \"{$source->key} {$type->value} {$target->key}\" to remove."]);

        DB::transaction(fn () => $this->remove($link, $actor));

        return $link;
    }

    /**
     * Web Undo of a duplicates link that replaced another original: link $duplicate to $original again. False, changing
     * nothing, when $original is gone or hidden from $actor (null), the issue has another original by now, or $actor
     * may no longer make the link.
     */
    public function restoreOriginal(Issue $duplicate, ?Issue $original, User $actor): bool
    {
        if (! $original || IssueLink::where('source_issue_id', $duplicate->id)->where('type', LinkType::Duplicates)->exists()) {
            return false;
        }
        try {
            $this->link($duplicate, LinkType::Duplicates->value, $original, $actor);

            return true;
        } catch (ValidationException|AuthorizationException) {
            return false;
        }
    }

    /**
     * "Close as Duplicate" (spec 7.4, 7.5): the duplicates link to $original (link() replaces one to another original).
     * It runs after the close committed (IssueService::linkDuplicate), so it never fails the close:
     * an error is reported and the Duplicate stays without its link.
     */
    public function ensureDuplicate(Issue $duplicate, Issue $original, User $actor): void
    {
        try {
            if (! $this->find($duplicate, LinkType::Duplicates, $original)) {
                $this->link($duplicate, LinkType::Duplicates->value, $original, $actor);
            }
        } catch (Throwable $e) {
            // A ValidationException here is the same link made by a request at the same instant: nothing to report.
            if (! $e instanceof ValidationException) {
                report($e);
            }
        }
    }

    /** The original a Duplicate points at, when $viewer can see it (2-D2), for Undo. */
    public function original(Issue $duplicate, User $viewer): ?Issue
    {
        return IssueLink::where('source_issue_id', $duplicate->id)->where('type', LinkType::Duplicates)
            ->whereHas('target', fn ($q) => $q->visibleTo($viewer))->latest('id')->with('target.project')->first()?->target;
    }

    /**
     * $issue's links that $viewer can see, grouped by the name read from $issue ("blocks", "is blocked by"…) in
     * LinkType::names() order. Links to deleted issues and to projects $viewer can't open are left out (2-D2).
     *
     * @return array<string, list<array{key: string, title: string, type: string, status: string, category: string, status_color: ?string}>>
     */
    public function visibleLinks(Issue $issue, User $viewer): array
    {
        $other = fn ($q) => $q->visibleTo($viewer);
        $links = IssueLink::where(fn ($q) => $q->where('source_issue_id', $issue->id)->whereHas('target', $other))
            ->orWhere(fn ($q) => $q->where('target_issue_id', $issue->id)->whereHas('source', $other))
            ->with(['source.project', 'source.status', 'target.project', 'target.status'])
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($links as $link) {
            $outgoing = $link->source_issue_id === $issue->id;
            $linked = $outgoing ? $link->target : $link->source;
            $grouped[$outgoing ? $link->type->value : $link->type->reverse()][] = [
                'key' => $linked->key,
                'title' => $linked->title,
                'type' => $linked->type->value,
                'status' => $linked->status->name,
                'category' => $linked->status->category->value,
                'status_color' => $linked->status->color,
            ];
        }

        return array_filter(array_map(fn ($name) => $grouped[$name] ?? null, array_combine(LinkType::names(), LinkType::names())));
    }

    /** Unresolved (not Done-category) issues blocking $issue that $viewer can see. @return Collection<int, Issue> */
    public function blockers(Issue $issue, User $viewer): Collection
    {
        return $issue->blockers()->open()->visibleTo($viewer)->with(['project', 'status'])->orderBy('issues.project_id')->orderBy('issues.number')->get();
    }

    /** Unresolved issues $issue follows that $viewer can see. @return Collection<int, Issue> */
    public function unresolvedPredecessors(Issue $issue, User $viewer): Collection
    {
        return $issue->predecessors()->open()->visibleTo($viewer)->with(['project', 'status'])->orderBy('issues.project_id')->orderBy('issues.number')->get();
    }

    /**
     * 2-D4: why moving $issue into $to deserves a confirm. Only entering an In Progress-category status warns: a blocked
     * issue, or a successor whose predecessor isn't resolved.
     *
     * @return list<string>
     */
    public function warnings(Issue $issue, Status $to, User $viewer): array
    {
        if ($to->category !== StatusCategory::InProgress || $issue->status?->category === StatusCategory::InProgress) {
            return [];
        }

        $sentence = function (string $verb, Collection $others) use ($issue) {
            $names = $others->map(fn (Issue $o) => "{$o->key} ({$o->status->name})");
            $list = $names->count() > 1 ? $names->slice(0, -1)->implode(', ').' and '.$names->last() : $names->first();

            return "{$issue->key} {$verb} {$list}, which ".($names->count() > 1 ? "aren't" : "isn't").' resolved.';
        };

        return array_values(array_filter([
            ($blockers = $this->blockers($issue, $viewer))->isNotEmpty() ? $sentence('is blocked by', $blockers) : null,
            ($predecessors = $this->unresolvedPredecessors($issue, $viewer))->isNotEmpty() ? $sentence('follows', $predecessors) : null,
        ]));
    }

    /** Most successors one date shift walks; a longer chain is cut off here. shortcut: a plain cap, raise it when a real chain needs more. */
    private const SHIFT_LIMIT = 500;

    /** Ids (and projects) of the successors of $moved that $actor could shift: what IssueService locks before it shifts. @return array<int, int> */
    public function shiftable(Issue $moved, User $actor): array
    {
        $graph = $this->graph($moved, $actor);

        return collect($graph['kind'])->filter(fn ($kind) => $kind === 'ok')->map(fn ($kind, $id) => $graph['nodes'][$id]->project_id)->all();
    }

    /**
     * Spec 7.5 (2-R6): where each conflicting successor of $moved would go, for "ask": the ones $actor can shift, then the
     * ones they can see but not edit, with a note. Successors they can't see are left out.
     *
     * @return list<array{key: string, start_date: ?string, due_date: ?string, note: ?string}>
     */
    public function proposedShifts(Issue $moved, User $actor): array
    {
        [$moves, $cant, $nodes] = $this->plan($moved, $actor);
        $row = fn (int $id, array $to, ?string $note = null) => [
            'key' => $nodes[$id]->key, 'start_date' => $to['start']?->format('Y-m-d'), 'due_date' => $to['due']?->format('Y-m-d'), 'note' => $note,
        ];

        return [
            ...array_map(fn ($id) => $row($id, $moves[$id]), array_keys($moves)),
            ...array_map(fn ($id) => $row($id, $cant[$id], "can't shift: you can't edit {$nodes[$id]->key}"), array_keys($cant)),
        ];
    }

    /** The sentence that goes with proposedShifts(): nothing moved, and how to move it. @param list<array{key: string, start_date: ?string, due_date: ?string, note: ?string}> $shifts */
    public static function shiftWarning(Issue $moved, array $shifts): string
    {
        $list = collect($shifts)->map(fn ($s) => $s['note'] ? "{$s['key']} ({$s['note']})" : "{$s['key']} to ".match (true) {
            $s['start_date'] && $s['due_date'] => "{$s['start_date']} – {$s['due_date']}",
            (bool) $s['due_date'] => "due {$s['due_date']}",
            default => "start {$s['start_date']}",
        })->implode(', ');

        return "{$moved->key}'s due date now falls after the start of its successors; their dates were not shifted. Proposed: {$list}. Send shift_successors: true to apply them.";
    }

    /**
     * Spec 7.5: move each conflicting successor of $moved later by just enough, keeping its duration, down the chain.
     * Runs inside IssueService::update's transaction (which has locked the rows, in id order); each one goes through
     * update() like any other change, so its version, activity and attribution are written. Returns how many moved.
     */
    public function shift(Issue $moved, User $actor): int
    {
        [$moves, , $nodes] = $this->plan($moved, $actor);
        ksort($moves);
        foreach ($moves as $id => $to) {
            // false: this walk already covers the chain, so the successor doesn't start another.
            $data = ['due_date' => $to['due']?->format('Y-m-d'), 'shift_successors' => false, 'shifted_by' => $moved->key];
            if ($nodes[$id]->start_date) {
                $data['start_date'] = $to['start']->format('Y-m-d');
            }
            app(IssueService::class)->update($nodes[$id], $actor, $data);
        }

        return count($moves);
    }

    /**
     * Spec 7.5: "a successor scheduled to start before its predecessor's due date warns on both". One sentence naming each
     * such pair $viewer can see, or null. A resolved issue, or a resolved other side, has nothing to schedule.
     */
    public function scheduleWarning(Issue $issue, User $viewer): ?string
    {
        if ($issue->status->isDone()) {
            return null;
        }
        $date = fn (?Carbon $d) => $d?->format('Y-m-d');
        $start = $issue->start_date ?? $issue->due_date;
        $sentences = [];
        if ($start) {
            foreach ($issue->predecessors()->open()->visibleTo($viewer)->whereNotNull('issues.due_date')->with('project')->orderBy('issues.id')->get() as $p) {
                $p->due_date->gt($start) && $sentences[] = "{$issue->key} starts on {$date($start)}, before {$p->key} is due on {$date($p->due_date)}.";
            }
        }
        if ($issue->due_date) {
            foreach ($issue->successors()->open()->visibleTo($viewer)->with('project')->orderBy('issues.id')->get() as $s) {
                $from = $s->start_date ?? $s->due_date;
                $from?->lt($issue->due_date) && $sentences[] = "{$s->key} starts on {$date($from)}, before {$issue->key} is due on {$date($issue->due_date)}.";
            }
        }

        return $sentences ? implode(' ', $sentences) : null;
    }

    /**
     * The successors of $moved through precedes links, among issues $actor can see (2-D2): a breadth-first walk with a
     * visited set, so a loop ends it. Each is `ok` (open and editable by $actor: it can move and the walk goes on through
     * it), `resolved` (never moves, the walk stops) or `cant` (visible, not editable: listed, the walk stops).
     *
     * @return array{nodes: array<int, Issue>, next: array<int, list<int>>, kind: array<int, string>}
     */
    private function graph(Issue $moved, User $actor): array
    {
        $nodes = $next = $kind = [];
        $seen = [$moved->id => true];
        $frontier = [$moved->id];
        while ($frontier && count($seen) <= self::SHIFT_LIMIT) {
            $edges = IssueLink::where('type', LinkType::Precedes)->whereIn('source_issue_id', $frontier)->orderBy('id')->get(['source_issue_id', 'target_issue_id']);
            $found = Issue::whereIn('id', $edges->pluck('target_issue_id')->unique())->visibleTo($actor)->with(['project', 'status'])->get()->keyBy('id');
            $frontier = [];
            foreach ($edges as $edge) {
                $successor = $found[$edge->target_issue_id] ?? null;
                if (! $successor) {
                    continue;
                }
                $next[$edge->source_issue_id][] = $successor->id;
                if (isset($seen[$successor->id])) {
                    continue;
                }
                $seen[$successor->id] = true;
                $nodes[$successor->id] = $successor;
                $kind[$successor->id] = match (true) {
                    $successor->status->isDone() => 'resolved',
                    Gate::forUser($actor)->allows('update', $successor) => 'ok',
                    default => 'cant',
                };
                if ($kind[$successor->id] === 'ok') {
                    $frontier[] = $successor->id;
                }
            }
        }

        return ['nodes' => $nodes, 'next' => $next, 'kind' => $kind];
    }

    /**
     * 2-R5: a successor conflicts when its start (its due date without a start) is before its predecessor's due date;
     * it moves by the gap, and its own successors are checked against its new due date. A successor behind two moved
     * ones is checked again against each, so it ends up after both. Bounded by one round per issue, so a loop can't run on.
     *
     * @return array{0: array<int, array{start: ?Carbon, due: ?Carbon}>, 1: array<int, array{start: ?Carbon, due: ?Carbon}>, 2: array<int, Issue>} the moves, the ones $actor can't make, and the issues
     */
    private function plan(Issue $moved, User $actor): array
    {
        $graph = $this->graph($moved, $actor);
        $moves = $cant = [];
        $frontier = [$moved->id];
        for ($round = 0; $frontier && $round <= count($graph['nodes']); $round++) {
            $changed = [];
            foreach ($frontier as $id) {
                $due = $id === $moved->id ? $moved->due_date : $moves[$id]['due'];
                foreach ($due ? ($graph['next'][$id] ?? []) : [] as $successorId) {
                    $kind = $graph['kind'][$successorId] ?? null;
                    if (! $kind || $kind === 'resolved') {
                        continue;
                    }
                    $successor = $graph['nodes'][$successorId];
                    $now = $moves[$successorId] ?? $cant[$successorId] ?? ['start' => $successor->start_date, 'due' => $successor->due_date];
                    $from = $now['start'] ?? $now['due'];
                    if (! $from || $from->gte($due)) {
                        continue;
                    }
                    $days = (int) round($from->diffInDays($due, true));
                    $to = ['start' => $now['start']?->copy()->addDays($days), 'due' => $now['due']?->copy()->addDays($days)];
                    if ($kind === 'ok') {
                        $moves[$successorId] = $to;
                        $changed[$successorId] = $successorId;
                    } else {
                        $cant[$successorId] = $to;
                    }
                }
            }
            $frontier = array_values($changed);
        }

        return [$moves, $cant, $graph['nodes']];
    }

    /**
     * The type and the issues as stored: a reverse name swaps them, and relates to (no direction) puts the lower id
     * first, so the unique index also catches the opposite pair.
     *
     * @return array{0: LinkType, 1: Issue, 2: Issue}
     */
    private function stored(Issue $from, string $name, Issue $to): array
    {
        [$type, $reversed] = LinkType::parse($name);
        $swap = $type === LinkType::RelatesTo ? $from->id > $to->id : $reversed;

        return [$type, ...($swap ? [$to, $from] : [$from, $to])];
    }

    private function find(Issue $source, LinkType $type, Issue $target): ?IssueLink
    {
        return IssueLink::where('type', $type)->where('source_issue_id', $source->id)->where('target_issue_id', $target->id)
            ->with(['source.project', 'target.project'])->first();
    }

    private function exists(Issue $source, LinkType $type, Issue $target): ValidationException
    {
        return ValidationException::withMessages(['type' => "{$source->key} already {$type->value} {$target->key}."]);
    }

    private function remove(IssueLink $link, User $actor): void
    {
        $link->delete();
        $this->logBoth('unlinked', $link->source, $link->type, $link->target, $actor);
    }

    /**
     * No loops for blocks and precedes (spec 7.5), through any issue of the workspace, deleted ones included (restoring
     * one must not complete a loop). A loop the actor can see is named; one through an issue they can't see is refused
     * without naming, counting or hinting at it (owner decision, narrowing 2-D2 for this case).
     */
    private function refuseLoop(Issue $source, LinkType $type, Issue $target, User $actor): void
    {
        $loop = $this->path($target, $source, $type, $actor);
        if ($loop) {
            $keys = Issue::withTrashed()->whereKey($loop)->with('project')->get()->mapWithKeys(fn (Issue $i) => [$i->id => $i->key]);

            throw ValidationException::withMessages(['to' => 'That would make a loop: '.implode(' → ', [...array_map(fn ($id) => $keys[$id], $loop), $target->key]).". \"{$type->value}\" links can't loop."]);
        }
        if ($this->path($target, $source, $type)) {
            throw ValidationException::withMessages(['to' => "That link would make a loop through issues you can't see. \"{$type->value}\" links can't loop."]);
        }
    }

    /**
     * Issue ids on a $type path from $from to $to, or null: a breadth-first walk with a visited set, deleted issues
     * included, over issues $viewer can see (or every issue, without $viewer; links never leave a workspace). Runs under
     * the workspace's link lock with locking reads, so it sees every link committed before it, whatever snapshot an
     * outer transaction holds.
     *
     * @return list<int>|null
     */
    private function path(Issue $from, Issue $to, LinkType $type, ?User $viewer = null): ?array
    {
        $parent = [$from->id => null];
        $frontier = [$from->id];
        while ($frontier && ! array_key_exists($to->id, $parent)) {
            $next = [];
            $edges = IssueLink::where('type', $type)->whereIn('source_issue_id', $frontier)
                ->when($viewer, fn ($q) => $q->whereHas('target', fn ($t) => $t->withTrashed()->visibleTo($viewer)))
                ->orderBy('id')->sharedLock()->get(['source_issue_id', 'target_issue_id']);
            foreach ($edges as $edge) {
                if (! array_key_exists($edge->target_issue_id, $parent)) {
                    $parent[$edge->target_issue_id] = $edge->source_issue_id;
                    $next[] = $edge->target_issue_id;
                }
            }
            $frontier = $next;
        }
        if (! array_key_exists($to->id, $parent)) {
            return null;
        }

        $ids = [];
        for ($id = $to->id; $id !== null; $id = $parent[$id]) {
            array_unshift($ids, $id);
        }

        return $ids;
    }

    private function logBoth(string $action, Issue $source, LinkType $type, Issue $target, User $actor): void
    {
        $change = fn (string $text, Issue $other) => ['link' => [
            'from' => $action === 'linked' ? null : $text,
            'to' => $action === 'linked' ? $text : null,
            // The other issue, so the entry is shown only to those who can see it (Activity::visibleTo).
            'issue_id' => $other->id,
        ]];
        $this->activity->log($source, $actor, $action, $change("{$type->value} {$target->key}", $target));
        $this->activity->log($target, $actor, $action, $change("{$type->reverse()} {$source->key}", $source));
    }
}
