<?php

namespace App\Services;

use App\Enums\LinkType;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\Status;
use App\Models\User;
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
 * Locks (2-R4): link writes run in a short transaction that takes no row locks of its own. The insert share-locks
 * both issue rows through the foreign keys, so it never runs while this request holds another issue row
 * (IssueService defers the Duplicate link until after its commit).
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
        if ($type->forbidsLoops() && $path = $this->path($target, $source, $type, $actor)) {
            throw ValidationException::withMessages(['to' => 'That would make a loop: '.implode(' → ', [...$path, $target->key]).". \"{$type->value}\" links can't loop."]);
        }

        try {
            return DB::transaction(function () use ($source, $type, $target, $actor) {
                if ($type === LinkType::Duplicates) {
                    // One original per Duplicate (Should 3): a new one replaces the old, logged on both pairs.
                    // shortcut: web Undo of a hand-made link removes the new one but doesn't bring back the one it replaced;
                    // carry the replaced key in the undo data if people rely on it.
                    IssueLink::where('source_issue_id', $source->id)->where('type', $type)
                        ->with(['source.project', 'target' => fn ($q) => $q->withTrashed()->with('project')])->get()
                        ->each(fn (IssueLink $old) => $this->remove($old, $actor));
                }
                $attribution = ActivityLogger::attribution($actor);
                $link = IssueLink::create([
                    'source_issue_id' => $source->id, 'target_issue_id' => $target->id, 'type' => $type,
                    'created_by' => $attribution['user_id'], 'agent_id' => $attribution['agent_id'], 'via' => $attribution['via'],
                ]);
                $this->logBoth('linked', $source, $type, $target, $actor);

                return $link->setRelation('source', $source)->setRelation('target', $target);
            });
        } catch (UniqueConstraintViolationException) {
            // The same link, sent twice at the same instant.
            throw $this->exists($source, $type, $target);
        }
    }

    /** Remove the link between $a and $b ($type may be the reverse name, as for link()). */
    public function unlink(Issue $a, string $type, Issue $b, User $actor): void
    {
        [$type, $source, $target] = $this->stored($a, $type, $b);
        $link = $this->find($source, $type, $target)
            ?? throw ValidationException::withMessages(['type' => "No link \"{$source->key} {$type->value} {$target->key}\" to remove."]);

        DB::transaction(fn () => $this->remove($link, $actor));
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
     * Keys on a $type path from $from to $to, or null: a breadth-first walk with a visited set, over issues $viewer can
     * see, deleted ones included (restoring one must not complete a loop).
     * shortcut: no lock (2-R4), so two links closing a loop at the same instant can both commit, and a loop through an
     * issue the linker can't see isn't found (refusing it would hint at the hidden link); such a loop then shows to
     * people who can see the whole chain. Every walk keeps a visited set, so a loop never hangs anything. Lock the link
     * graph per workspace if loops start showing up.
     *
     * @return list<string>|null
     */
    private function path(Issue $from, Issue $to, LinkType $type, User $viewer): ?array
    {
        $parent = [$from->id => null];
        $frontier = [$from->id];
        while ($frontier && ! array_key_exists($to->id, $parent)) {
            $next = [];
            $edges = IssueLink::where('type', $type)->whereIn('source_issue_id', $frontier)
                ->whereHas('target', fn ($q) => $q->withTrashed()->visibleTo($viewer))->orderBy('id')->get(['source_issue_id', 'target_issue_id']);
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
        $keys = Issue::withTrashed()->whereKey($ids)->with('project')->get()->mapWithKeys(fn (Issue $i) => [$i->id => $i->key]);

        return array_map(fn ($id) => $keys[$id], $ids);
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
