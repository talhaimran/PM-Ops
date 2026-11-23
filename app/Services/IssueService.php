<?php

namespace App\Services;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\Resolution;
use App\Enums\SprintState;
use App\Models\Activity;
use App\Models\Column;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueService
{
    /** Relations needed to render an issue (IssueResource) and its activity diff. */
    public const DISPLAY = ['project', 'status', 'assignee', 'reporter', 'epic', 'parent', 'labels', 'sprint'];

    public function __construct(
        private ProjectService $projects,
        private ActivityLogger $activity,
        private RankService $ranks,
        private ApprovalService $approvals,
        private StatusService $statuses,
    ) {}

    /**
     * Create an issue. $data uses the shared input shape: status = status name, assignee = email,
     * labels = names, epic/parent = issue keys.
     */
    public function create(Project $project, User $actor, array $data): Issue
    {
        return DB::transaction(function () use ($project, $actor, $data) {
            // The project row first (as moves and column changes do), so the status read below can't race a column delete.
            Project::whereKey($project->id)->lockForUpdate()->first();
            [$attributes, $labelIds, $status] = $this->resolveInput($project, $data, null);

            // A-L6: an agent can't skip the approval by creating the issue in Done, even when Done is the default (first) status.
            $status ??= $project->statuses()->first();
            if ($this->approvals->needsApproval(new Issue, $status, $actor->agent())) {
                throw ValidationException::withMessages([
                    'status' => "Agents can't create issues in {$status->name}. Create it in another status, then move it; a person approves the move.",
                ]);
            }

            [$resolution, $original] = $this->resolution($project, null, $status, $data, $actor);
            $issue = Issue::create([
                'type' => IssueType::Task,
                'priority' => IssuePriority::Medium,
                'status_id' => $status->id,
                ...$attributes,
                ...$resolution,
                'project_id' => $project->id,
                'number' => $this->nextNumber($project),
                'rank' => $this->ranks->bottom($project),
                'reporter_id' => $actor->id,
            ]);
            $issue->labels()->sync($labelIds ?? []);
            $issue->setRelation('project', $project);
            $this->activity->log($issue, $actor, 'created', $original ? ['duplicate_of' => ['from' => null, 'to' => $original]] : null);

            return $issue->load(array_slice(self::DISPLAY, 1));
        });
    }

    /**
     * $action is 'updated', 'undid' when the web UI's Undo sends a change back, or 'approved' for an approved request.
     * An agent's move to Done is held instead (A2): the other fields still change (A-L7) and $issue->heldApproval
     * holds the request; $data['reason'] (A-L5) goes on it.
     */
    public function update(Issue $issue, User $actor, array $data, string $action = 'updated'): Issue
    {
        return DB::transaction(function () use ($issue, $actor, $data, $action) {
            if (isset($data['status']) || isset($data['resolution'])) {
                // The project row first (as moves and column changes do), so the status read can't race a column delete.
                Project::whereKey($issue->project_id)->lockForUpdate()->first();
                // Then the issue as it is now: a copy loaded earlier may have been reopened or moved since.
                $issue->refresh();
            }
            $issue->loadMissing(self::DISPLAY);
            $before = $this->snapshot($issue);
            $wasDone = $issue->status->isDone();
            [$attributes, $labelIds, $status] = $this->resolveInput($issue->project, $data, $issue);
            [$resolution, $original] = $this->resolution($issue->project, $issue, $status, $data, $actor);

            $issue->heldApproval = null;
            if ($status && $this->approvals->needsApproval($issue, $status, $agent = $actor->agent())) {
                unset($attributes['status_id']);
                // The issue stays where it is, so its resolution does too; the request carries the new one.
                $resolution = [];
                $issue->heldApproval = $this->approvals->requestDone($issue, $actor, $agent, $status, $data['reason'] ?? null, isset($data['resolution']) ? Resolution::from($data['resolution']) : null, $data['duplicate_of'] ?? null);
            }

            $issue->update([...$attributes, ...$resolution]);
            if ($labelIds !== null) {
                $issue->labels()->sync($labelIds);
            }
            $issue->load(array_slice(self::DISPLAY, 1));

            $after = $this->snapshot($issue);
            $changes = collect($before)
                ->filter(fn ($value, $field) => $value !== $after[$field])
                ->map(fn ($value, $field) => ['from' => $value, 'to' => $after[$field]])
                ->all();

            if ($original && $resolution) {
                $changes['duplicate_of'] = ['from' => null, 'to' => $original];
            }
            if ($changes) {
                // Leaving Done is a reopen (spec 7.4); an undo or an approval keeps its own action.
                $reopened = $wasDone && ! $issue->status->isDone();
                if ($reopened && $action !== 'updated') {
                    // An Undo keeps its own entry (and its changes) but still leaves the reopen marker before it.
                    $this->activity->log($issue, $actor, 'reopened');
                }
                $this->activity->log($issue, $actor, $reopened && $action === 'updated' ? 'reopened' : $action, $changes);
            }

            return $issue;
        });
    }

    /**
     * Create several issues in one transaction: all or nothing. Item errors are keyed "issues.{i}.{field}".
     *
     * @return Collection<int, Issue>
     */
    public function createMany(Project $project, User $actor, array $items): Collection
    {
        return DB::transaction(fn () => collect($items)->values()->map(
            fn ($data, $i) => $this->forItem("issues.{$i}", fn () => $this->create($project, $actor, $data))
        ));
    }

    /**
     * Update several issues, each addressed by "key", in one transaction: all or nothing.
     * Each issue is authorized separately. Item errors are keyed "updates.{i}.{field}".
     *
     * @return Collection<int, Issue>
     */
    public function updateMany(User $actor, array $items): Collection
    {
        return DB::transaction(function () use ($actor, $items) {
            $items = collect($items)->values();
            $issues = $items->map(function ($data, $i) use ($actor) {
                $issue = $this->forItem("updates.{$i}", fn () => $this->resolve($actor, $data['key']), as: 'key');
                Gate::forUser($actor)->authorize('update', $issue);

                return $issue;
            });
            // Every project row first, in id order, before any issue row: the order move and approve use, so no deadlock.
            Project::whereIn('id', $issues->pluck('project_id')->unique())->orderBy('id')->lockForUpdate()->get();

            // A fresh copy: an earlier item may have changed the same issue.
            return $issues->map(fn ($issue, $i) => $this->forItem("updates.{$i}", fn () => $this->update($issue->refresh(), $actor, collect($items[$i])->except('key')->all())));
        });
    }

    /** Run one bulk item, re-keying its validation errors under $prefix (or $prefix.$as) and prefixing messages. */
    private function forItem(string $prefix, callable $run, ?string $as = null): mixed
    {
        try {
            return $run();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => [
                "{$prefix}.".($as ?? $field) => array_map(fn ($m) => "[{$prefix}] {$m}", $messages),
            ])->all());
        }
    }

    /**
     * Soft delete: the issue and its sub-tasks disappear everywhere but keep their rows, keys and history
     * until issues:purge removes them. Issues in a deleted epic keep their link; the epic just isn't shown.
     */
    public function delete(Issue $issue, User $actor): Activity
    {
        return DB::transaction(function () use ($issue, $actor) {
            // One timestamp for the issue and its sub-tasks, so restore() can tell them from sub-tasks deleted earlier.
            // shortcut: second precision, so a sub-task deleted in the same second as its parent is restored with it.
            $at = now()->startOfSecond();
            Issue::where(fn ($q) => $q->whereKey($issue->id)->orWhere('parent_id', $issue->id))->update(['deleted_at' => $at]);
            $issue->setAttribute('deleted_at', $at)->syncOriginal();

            return $this->activity->log($issue, $actor, 'deleted');
        });
    }

    /** Bring a deleted issue back, with the sub-tasks that were deleted along with it. */
    public function restore(Issue $issue, User $actor): Issue
    {
        $issue->loadMissing('project');
        $parent = $issue->parent_id ? Issue::onlyTrashed()->find($issue->parent_id) : null;

        if ($parent) {
            throw ValidationException::withMessages([
                'issue' => "{$issue->key} is a sub-task of {$issue->project->key}-{$parent->number}, which is deleted. Restore {$issue->project->key}-{$parent->number} first.",
            ]);
        }

        return DB::transaction(function () use ($issue, $actor) {
            Issue::onlyTrashed()
                ->where(fn ($q) => $q->whereKey($issue->id)->orWhere('parent_id', $issue->id))
                ->where('deleted_at', $issue->deleted_at)
                ->restore();
            $issue->setAttribute('deleted_at', null)->syncOriginal();
            $this->activity->log($issue, $actor, 'restored');

            return $issue;
        });
    }

    /**
     * Deleted issues of a project, newest first, for the Admin list in project settings.
     *
     * @return list<array{key: string, title: string, deleted_at: string, deleted_by: ?string}>
     */
    public function deleted(Project $project): array
    {
        return $project->issues()->onlyTrashed()
            // Sub-tasks deleted with their parent come back with it, so only the parent is listed.
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhereHas('parent'))
            ->with(['activities' => fn ($q) => $q->where('action', 'deleted')->with('user')->latest('id')])
            ->latest('deleted_at')
            ->limit(200)
            ->get()
            ->map(fn (Issue $issue) => [
                'key' => "{$project->key}-{$issue->number}",
                'title' => $issue->title,
                'deleted_at' => $issue->deleted_at->toIso8601String(),
                'deleted_by' => $issue->activities->first()?->user?->name,
            ])
            ->all();
    }

    /** Find a deleted issue by key, with the same errors as resolve(). */
    public function resolveDeleted(User $user, string $key): Issue
    {
        return $this->resolve($user, $key, deleted: true);
    }

    /** Find an issue by key ("WEB-12", case-insensitive) in a project the user belongs to. */
    public function resolve(User $user, string $key, bool $deleted = false): Issue
    {
        if (! preg_match('/^([A-Za-z][A-Za-z0-9]{1,9})-(\d+)$/', trim($key), $m)) {
            throw ValidationException::withMessages([
                'issue' => "\"{$key}\" is not an issue key. Issue keys look like WEB-12.",
            ])->status(404);
        }

        $project = $this->projects->resolve($user, $m[1]);
        $issue = $project->issues()->when($deleted, fn ($q) => $q->onlyTrashed())->where('number', $m[2])->first();

        if (! $issue) {
            throw ValidationException::withMessages([
                'issue' => "Issue {$project->key}-{$m[2]} was not found. Search the project's issues to find the right key.",
            ])->status(404);
        }

        return $issue->setRelation('project', $project);
    }

    /** The issue for the peek drawer (?issue=KEY): null for anything the user can't open, never an error. */
    public function peek(User $user, mixed $key): ?Issue
    {
        try {
            return is_string($key) && $key !== '' ? $this->resolve($user, $key) : null;
        } catch (ValidationException) {
            return null;
        }
    }

    /** Load what IssueResource needs for the full single-issue view. */
    public function loadFull(Issue $issue): Issue
    {
        return $issue->loadMissing(self::DISPLAY)->load([
            'subtasks' => fn ($q) => $q->with('status')->orderBy('number'),
            'epicIssues' => fn ($q) => $q->with('status')->orderBy('number')->limit(100),
            'comments' => fn ($q) => $q->with(['author', 'agent.owner'])->oldest()->limit(100),
            'pendingApprovals.agent.owner',
        ]);
    }

    /** Sort keys accepted by sort(); prefix with "-" for descending. */
    public const SORTS = ['key', 'title', 'type', 'priority', 'status', 'assignee', 'story_points', 'due_date', 'created', 'updated'];

    /**
     * Apply board/list/search filters: assignee (email or "unassigned"), type, priority, label (name),
     * sprint (name, "active" or "backlog"), category (to_do, in_progress, done), resolution, q (title text, or an issue key such as WEB-12).
     */
    public function filter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['assignee'] ?? null, fn ($q, $assignee) => strtolower($assignee) === 'unassigned'
                ? $q->whereNull('assignee_id')
                : $q->whereHas('assignee', fn ($u) => $u->where('email', $assignee)))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->whereHas('status', fn ($s) => $s->where('category', $category)))
            ->when($filters['resolution'] ?? null, fn ($q, $resolution) => $q->where('resolution', $resolution))
            ->when($filters['sprint'] ?? null, fn ($q, $sprint) => match (strtolower($sprint)) {
                'backlog' => $q->whereNull('sprint_id'),
                'active' => $q->whereHas('sprint', fn ($s) => $s->where('state', SprintState::Active->value)),
                default => $q->whereHas('sprint', fn ($s) => $s->where('name', $sprint)),
            })
            ->when($filters['label'] ?? null, fn ($q, $label) => $q->whereHas('labels', fn ($l) => $l->where('name', $label)))
            ->when(trim($filters['q'] ?? ''), fn ($q, $search) => preg_match('/^([A-Za-z][A-Za-z0-9]{1,9})-(\d+)$/', $search, $m)
                ? $q->where('number', $m[2])->whereHas('project', fn ($p) => $p->where('key', $m[1]))
                : $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'));
    }

    /**
     * Filtered, sorted, paginated issues in one project, or across all of the user's projects.
     * $params: filters (see filter()), sort, per_page (max 100), page or cursor.
     */
    public function list(User $user, ?Project $project, array $params): LengthAwarePaginator
    {
        $query = $project
            ? $project->issues()->getQuery()
            : Issue::whereIn('project_id', $user->accessibleProjects()->select('projects.id'));

        [$perPage, $page] = Pagination::resolve($params['cursor'] ?? null, $params['per_page'] ?? null, $params['page'] ?? null, max: $params['max_per_page'] ?? 100);

        return $this->sort($this->filter($query, $params), $params['sort'] ?? null)
            ->with(self::DISPLAY)
            ->paginate($perPage, page: $page);
    }

    /** Order by one of SORTS ("-" prefix = descending); ties break by id in the same direction. */
    public function sort(Builder $query, ?string $sort): Builder
    {
        $sort = $sort ?: '-key';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');

        match ($field) {
            'key' => $query->orderBy('issues.project_id', $direction)->orderBy('issues.number', $direction),
            'priority' => $query->orderByRaw('FIELD(issues.priority, '.$this->quoted(IssuePriority::values()).') '.$direction),
            'type' => $query->orderByRaw('FIELD(issues.type, '.$this->quoted(IssueType::values()).') '.$direction),
            // Board order: the mapped column's position, then the status's place in that column.
            'status' => $query->orderBy($this->mappedColumn('columns.position'), $direction)->orderBy($this->mappedColumn('column_statuses.position'), $direction),
            'assignee' => $query->orderBy(User::select('name')->whereColumn('users.id', 'issues.assignee_id'), $direction),
            'created' => $query->orderBy('issues.created_at', $direction),
            'updated' => $query->orderBy('issues.updated_at', $direction),
            default => $query->orderBy("issues.{$field}", $direction),
        };

        return $query->orderBy('issues.id', $direction);
    }

    /** $field of the issue's status mapping in its own project, as an order-by subquery. */
    private function mappedColumn(string $field): Builder
    {
        return Column::select($field)->join('column_statuses', 'column_statuses.column_id', '=', 'columns.id')
            ->whereColumn('column_statuses.status_id', 'issues.status_id')->whereColumn('columns.project_id', 'issues.project_id');
    }

    private function quoted(array $values): string
    {
        return collect($values)->map(fn ($v) => "'{$v}'")->implode(', ');
    }

    private function nextNumber(Project $project): int
    {
        // Row lock serialises concurrent creates in the same project; unique(project_id, number) is the backstop.
        $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
        $locked->increment('issue_counter');

        return $locked->issue_counter;
    }

    /**
     * Turn the shared input shape into column values. Only keys present in $data are touched.
     *
     * @return array{0: array<string, mixed>, 1: ?list<int>, 2: ?Status} the status when `status` was given
     */
    private function resolveInput(Project $project, array $data, ?Issue $issue): array
    {
        $attributes = collect($data)->only(['title', 'description', 'story_points', 'due_date'])->all();
        $labelIds = $status = null;

        if (isset($data['type'])) {
            $attributes['type'] = IssueType::from($data['type']);
        }
        if (isset($data['priority'])) {
            $attributes['priority'] = IssuePriority::from($data['priority']);
        }
        if (isset($data['status'])) {
            $status = $this->statuses->resolveFor($project, $data['status']);
            $attributes['status_id'] = $status->id;
        }
        if (array_key_exists('assignee', $data)) {
            $attributes['assignee_id'] = $data['assignee'] === null ? null : $this->resolveAssigneeId($project, $data['assignee']);
        }
        if (array_key_exists('sprint', $data)) {
            $attributes['sprint_id'] = $data['sprint'] === null ? null : $this->resolveOpenSprintId($project, (string) $data['sprint']);
        }
        if (array_key_exists('labels', $data)) {
            $labelIds = $this->resolveLabelIds($project, $data['labels'] ?? []);
        }

        $epic = $parent = null;
        if (array_key_exists('epic', $data)) {
            $epic = $data['epic'] === null ? null : $this->resolveLinked($project, $data['epic'], 'epic');
            $attributes['epic_id'] = $epic?->id;
        }
        if (array_key_exists('parent', $data)) {
            $parent = $data['parent'] === null ? null : $this->resolveLinked($project, $data['parent'], 'parent');
            $attributes['parent_id'] = $parent?->id;
        }

        if (array_intersect(['type', 'epic', 'parent'], array_keys($data))) {
            $this->validateLinks($project, $issue, $attributes, $epic, $parent);
        }

        return [$attributes, $labelIds, $status];
    }

    /**
     * Spec 7.4, the one place the resolution rule lives (web, REST, MCP, bulk and approvals all come through update):
     * entering a Done-category status sets a resolution (default Done) and resolved_at, leaving clears both, and a
     * resolution only goes with a Done status. $to is the status asked for, or null to stay where the issue is.
     *
     * @return array{0: array<string, mixed>, 1: ?string} the columns to save, and the original's key for a Duplicate
     */
    private function resolution(Project $project, ?Issue $issue, ?Status $to, array $data, User $actor): array
    {
        $target = $to ?? $issue?->status;
        $wasDone = $issue?->status?->isDone() ?? false;
        $given = ($data['resolution'] ?? null) ? Resolution::from($data['resolution']) : null;
        $duplicateOf = $data['duplicate_of'] ?? null;

        if (! $target?->isDone()) {
            if ($given || $duplicateOf) {
                $done = $project->statuses()->get()->filter->isDone()->pluck('name');
                throw ValidationException::withMessages(['resolution' => $done->isEmpty()
                    ? "{$project->key} has no Done status. A project Admin must add a column with a Done status before issues can be closed."
                    : 'Resolution applies to Done statuses. Move the issue to one of: '.$done->implode(', ').'.']);
            }

            return [$wasDone ? ['resolution' => null, 'resolved_at' => null] : [], null];
        }

        $original = null;
        if ($given === Resolution::Duplicate) {
            $original = $this->original($project, $issue, $actor, $duplicateOf);
        } elseif ($duplicateOf) {
            throw ValidationException::withMessages(['duplicate_of' => 'duplicate_of goes with the Duplicate resolution.']);
        }

        return match (true) {
            ! $wasDone => [['resolution' => ($given ?? Resolution::Done)->value, 'resolved_at' => now()], $original],
            // Still Done: only a new resolution changes, resolved_at stays.
            $given !== null => [['resolution' => $given->value], $original],
            default => [[], null],
        };
    }

    /** The key of the issue a Duplicate points at, found only among what $actor can open; unknown and inaccessible read the same. */
    private function original(Project $project, ?Issue $issue, User $actor, mixed $key): string
    {
        $hint = "Send duplicate_of with the original issue's key, e.g. {$project->key}-3.";
        if (! is_string($key) || trim($key) === '') {
            throw ValidationException::withMessages(['duplicate_of' => $hint]);
        }

        try {
            $original = $this->resolve($actor, $key);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['duplicate_of' => "Issue \"{$key}\" was not found. {$hint}"]);
        }
        if ($issue?->is($original)) {
            throw ValidationException::withMessages(['duplicate_of' => "An issue can't be a duplicate of itself."]);
        }

        return $original->key;
    }

    private function validateLinks(Project $project, ?Issue $issue, array $attributes, ?Issue $epic, ?Issue $parent): void
    {
        $type = $attributes['type'] ?? $issue?->type ?? IssueType::Task;
        $epic ??= array_key_exists('epic_id', $attributes) ? null : $issue?->epic;
        $parent ??= array_key_exists('parent_id', $attributes) ? null : $issue?->parent;
        $key = fn (Issue $i) => "{$project->key}-{$i->number}";
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($epic) {
            if ($type === IssueType::Epic) {
                $fail('epic', 'Epics cannot belong to another epic.');
            }
            if ($epic->type !== IssueType::Epic) {
                $fail('epic', "{$key($epic)} is a {$epic->type->value}, not an epic. Use the parent field to make a sub-task.");
            }
        }

        if ($parent) {
            if ($type === IssueType::Epic) {
                $fail('parent', 'Epics cannot be sub-tasks.');
            }
            if ($issue && $parent->is($issue)) {
                $fail('parent', 'An issue cannot be its own parent.');
            }
            if ($parent->type === IssueType::Epic) {
                $fail('parent', "{$key($parent)} is an epic. Use the epic field to link to an epic.");
            }
            if ($parent->parent_id) {
                $fail('parent', "{$key($parent)} is already a sub-task; sub-tasks are only one level deep.");
            }
            if ($issue?->subtasks()->exists()) {
                $fail('parent', "{$key($issue)} has sub-tasks, so it cannot become a sub-task.");
            }
        }

        if ($issue && $type === IssueType::Epic && $issue->type !== IssueType::Epic && $issue->subtasks()->exists()) {
            $fail('type', "{$key($issue)} has sub-tasks, so it cannot become an epic.");
        }
        if ($issue && $type !== IssueType::Epic && $issue->type === IssueType::Epic && $issue->epicIssues()->exists()) {
            $fail('type', "{$key($issue)} has issues linked to it as an epic; unlink them before changing its type.");
        }
    }

    private function resolveOpenSprintId(Project $project, string $ref): int
    {
        $sprints = $project->sprints()->orderBy('id')->get(['id', 'name', 'state']);
        $sprint = $sprints->first(fn ($s) => (string) $s->id === trim($ref) || strcasecmp($s->name, trim($ref)) === 0);
        $open = $sprints->where('state', '!=', SprintState::Completed);

        if (! $sprint || $sprint->state === SprintState::Completed) {
            $reason = $sprint ? "{$sprint->name} is completed" : "Unknown sprint \"{$ref}\"";
            $valid = $open->isEmpty() ? "{$project->key} has no open sprints; create one first" : 'Open sprints: '.$open->pluck('name')->implode(', ');
            throw ValidationException::withMessages(['sprint' => "{$reason}. {$valid}. Use null for the backlog."]);
        }

        return $sprint->id;
    }

    private function resolveAssigneeId(Project $project, string $email): int
    {
        $id = $project->members()->where('email', strtolower(trim($email)))->value('users.id');

        if (! $id) {
            $emails = $project->members()->orderBy('email')->limit(50)->pluck('email')->implode(', ');
            throw ValidationException::withMessages([
                'assignee' => "{$email} is not a member of {$project->key}. Assign one of: {$emails}.",
            ]);
        }

        return $id;
    }

    /** @return list<int> */
    private function resolveLabelIds(Project $project, array $names): array
    {
        $labels = $project->labels()->get(['id', 'name']);
        $byName = $labels->keyBy(fn ($l) => Str::lower($l->name));
        $missing = collect($names)->reject(fn ($n) => $byName->has(Str::lower(trim($n))));

        if ($missing->isNotEmpty()) {
            $valid = $labels->isEmpty() ? "{$project->key} has no labels yet" : "Labels in {$project->key}: ".$labels->pluck('name')->implode(', ');
            throw ValidationException::withMessages([
                'labels' => 'Unknown labels: '.$missing->implode(', ')."; {$valid}. Create a label first to use it.",
            ]);
        }

        return collect($names)->map(fn ($n) => $byName[Str::lower(trim($n))]->id)->unique()->values()->all();
    }

    private function resolveLinked(Project $project, string $key, string $field): Issue
    {
        $prefix = $project->key.'-';
        $number = Str::startsWith(Str::upper(trim($key)), $prefix) ? Str::after(Str::upper(trim($key)), $prefix) : null;
        $linked = ctype_digit((string) $number) ? $project->issues()->where('number', $number)->first() : null;

        if (! $linked) {
            throw ValidationException::withMessages([
                $field => "Issue \"{$key}\" was not found in {$project->key}. Links must point to an issue in the same project (e.g. {$prefix}1).",
            ]);
        }

        return $linked;
    }

    /** Human-readable values used for activity diffs. */
    private function snapshot(Issue $issue): array
    {
        $key = fn (?Issue $i) => $i ? "{$issue->project->key}-{$i->number}" : null;

        return [
            'title' => $issue->title,
            'description' => $issue->description === null ? null : Str::limit($issue->description, 200),
            'type' => $issue->type->value,
            'priority' => $issue->priority->value,
            'status' => $issue->status->name,
            'resolution' => $issue->resolution?->value,
            'assignee' => $issue->assignee?->email,
            'labels' => $issue->labels->pluck('name')->sort()->values()->implode(', ') ?: null,
            'story_points' => $issue->story_points,
            'due_date' => $issue->due_date?->format('Y-m-d'),
            'epic' => $key($issue->epic),
            'parent' => $key($issue->parent),
            'sprint' => $issue->sprint?->name,
        ];
    }
}
