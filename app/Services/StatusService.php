<?php

namespace App\Services;

use App\Enums\StatusCategory;
use App\Models\ApprovalRequest;
use App\Models\Column;
use App\Models\IssueStatusHistory;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 7.4: the workspace status library and every name lookup into it. */
class StatusService
{
    /** Spec 7.4: the colours a status may take (tint-* classes); null is the category's own colour. */
    public const PALETTE = ['slate', 'sky', 'amber', 'cobalt', 'coral', 'teal', 'sand', 'good'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * The library status for a new column: the one with this name and category, else a new one. A name already used
     * with another category gets the project key, "Done (API)". Logs status.created when $actor is given.
     */
    public function forColumn(Workspace $workspace, string $name, StatusCategory $category, string $projectKey, ?User $actor = null): Status
    {
        foreach ([trim($name), trim($name)." ({$projectKey})"] as $candidate) {
            $status = Status::firstOrCreate(['workspace_id' => $workspace->id, 'name' => $candidate], ['category' => $category]);
            if ($status->category !== $category) {
                continue;
            }
            if ($status->wasRecentlyCreated && $actor) {
                $this->audit->log('status.created', $status, ['name' => $status->name, 'category' => $category->value], $workspace, $actor);
            }

            return $status;
        }

        throw ValidationException::withMessages([
            'name' => "Statuses \"{$name}\" and \"{$candidate}\" already exist with other categories. Pick another name, or map an existing status.",
        ]);
    }

    /** A library status by name (case-insensitive) in $workspace; the error lists that workspace's statuses only. */
    public function named(Workspace $workspace, string $name): Status
    {
        $status = $workspace->statuses()->where('name', trim($name))->first();

        if (! $status) {
            $names = $workspace->statuses()->orderBy('name')->limit(50)->pluck('name')->implode(', ');
            throw ValidationException::withMessages(['status' => "Unknown status \"{$name}\". Statuses in this workspace: {$names}."]);
        }

        return $status;
    }

    /**
     * The status an issue in $project may take for $name (1-R2: only statuses mapped to its columns): a status name,
     * or a column name when that column has exactly one status.
     */
    public function resolveFor(Project $project, string $name): Status
    {
        $mapped = $project->statuses()->get();
        $status = $mapped->first(fn ($s) => strcasecmp($s->name, trim($name)) === 0);

        if (! $status) {
            $inColumn = $mapped->filter(fn ($s) => strcasecmp($s->column_name, trim($name)) === 0);
            $status = $inColumn->count() === 1 ? $inColumn->first() : null;
        }

        if (! $status) {
            throw ValidationException::withMessages([
                'status' => "Unknown status \"{$name}\". Valid statuses for {$project->key}: ".$mapped->pluck('name')->implode(', ').'.',
            ]);
        }

        return $status;
    }

    /** Map $status to $column, last in it. Callers hold the project row lock, so two columns can't take one status. */
    public function attach(Column $column, Status $status): void
    {
        $project = $column->loadMissing('project')->project;
        if ($status->workspace_id !== $project->workspace_id) {
            // Never reachable through a name lookup; a guard so another workspace's status can't be attached at all.
            throw ValidationException::withMessages(['status' => "Unknown status \"{$status->name}\"."]);
        }

        $taken = $project->columns()->whereKeyNot($column->id)->whereRelation('statuses', 'statuses.id', $status->id)->value('name');
        if ($taken) {
            throw ValidationException::withMessages([
                'status' => "{$status->name} is already in the {$taken} column of {$project->key}. A status can be in one column per project.",
            ]);
        }

        $column->statuses()->syncWithoutDetaching([$status->id => ['position' => $column->statuses()->count()]]);
    }

    /** The column editor's "Create": a new library status. An existing name is a 422 that says to pick it instead. Call inside the workspace lock. */
    public function create(Workspace $workspace, User $actor, string $name, StatusCategory $category): Status
    {
        $name = trim($name);
        if ($existing = $workspace->statuses()->where('name', $name)->first()) {
            throw ValidationException::withMessages(['name' => "A status named \"{$name}\" already exists ({$existing->category->label()}). Pick it instead of creating it."]);
        }
        $status = Status::create(['workspace_id' => $workspace->id, 'name' => $name, 'category' => $category]);
        $this->audit->log('status.created', $status, ['name' => $status->name, 'category' => $category->value], $workspace, $actor);

        return $status;
    }

    /** A library status by id in $workspace; another workspace's id is the same 404 as a missing one and lists this workspace's names only. */
    public function find(Workspace $workspace, int $id): Status
    {
        return $workspace->statuses()->find($id) ?? throw ValidationException::withMessages([
            'status' => 'Unknown status. Statuses in this workspace: '.$workspace->statuses()->orderBy('name')->limit(50)->pluck('name')->implode(', ').'.',
        ])->status(404);
    }

    public function rename(Status $status, User $actor, string $name): Status
    {
        $name = trim($name);

        return $this->locked($status, function (Status $status, Workspace $workspace) use ($actor, $name) {
            $names = fn () => $workspace->statuses()->orderBy('name')->pluck('name')->implode(', ');
            if ($workspace->statuses()->where('name', $name)->whereKeyNot($status->id)->exists()) {
                throw ValidationException::withMessages(['name' => "A status named \"{$name}\" already exists. Names in this workspace: {$names()}."]);
            }
            $from = $status->name;
            if ($from !== $name) {
                $status->update(['name' => $name]);
                $this->audit->log('status.renamed', $status, ['from' => $from, 'to' => $name], $workspace, $actor);
            }

            return $status;
        });
    }

    public function recolour(Status $status, User $actor, ?string $color): Status
    {
        return $this->locked($status, function (Status $status, Workspace $workspace) use ($actor, $color) {
            $from = $status->color;
            if ($from !== $color) {
                $status->update(['color' => $color]);
                $this->audit->log('status.recoloured', $status, ['name' => $status->name, 'from' => $from, 'to' => $color], $workspace, $actor);
            }

            return $status;
        });
    }

    /** Only when no issue (soft-deleted included) and no column uses it. */
    public function delete(Status $status, User $actor): void
    {
        $this->locked($status, function (Status $status, Workspace $workspace) use ($actor) {
            $use = $this->usage($workspace)->firstWhere('id', $status->id);
            $columns = DB::table('column_statuses')->where('status_id', $status->id)->count();
            if ($columns || $use->issues_count) {
                $parts = array_filter([
                    $columns ? "{$columns} ".str('column')->plural($columns)." in {$use->projects_count} ".str('project')->plural($use->projects_count) : null,
                    $use->issues_count ? "{$use->issues_count} ".str('issue')->plural($use->issues_count) : null,
                ]);
                throw ValidationException::withMessages(['status' => "{$status->name} is used by ".implode(' and ', $parts).'. Merge it into another status, or move them first.']);
            }
            $status->delete();
            $this->audit->log('status.deleted', $status, ['name' => $status->name, 'category' => $status->category->value], $workspace, $actor);
        });
    }

    /**
     * 1-D5: moves everything on $from to $into (same category) and deletes $from; returns the issues moved. Locks the
     * workspace, then the projects that map either status (id order), so a move or column change racing it waits and then
     * resolves against the merged library. Other projects aren't held: their issues can't take either status (1-R2), and
     * mapping one needs the workspace lock this holds.
     */
    public function merge(Status $from, string $intoName, User $actor): int
    {
        return $this->locked($from, function (Status $from, Workspace $workspace) use ($intoName, $actor) {
            $into = $this->named($workspace, $intoName);
            if ($into->is($from)) {
                throw ValidationException::withMessages(['into' => "Pick a different status to merge {$from->name} into."]);
            }
            if ($into->category !== $from->category) {
                throw ValidationException::withMessages(['status' => "{$from->name} is {$from->category->label()} and {$into->name} is {$into->category->label()}. Statuses merge only within the same category."]);
            }

            // Lock order: workspace, pending requests (id order), projects, like ApprovalService::approve (request, then project).
            // shortcut: this locking read holds every pending request of the workspace it scans (and blocks new ones), so approvals
            // wait for a merge; fine while pending requests are few and merges rare, narrow it if approvals queue up behind merges.
            $requests = ApprovalRequest::where('workspace_id', $workspace->id)->where('status', 'pending')
                ->where(fn ($q) => $q->where('action->to_status_id', $from->id)->orWhere('action->from_status_id', $from->id))
                ->orderBy('id')->lockForUpdate()->get();
            // Locked explicitly; the mapping read below joins no projects, so it holds only the mapping and column rows it finds.
            // The ids are read first and locked by primary key: a locking read through workspace_id would hold every project it scans.
            $projectIds = DB::table('column_statuses')->join('columns', 'columns.id', '=', 'column_statuses.column_id')
                ->whereIn('column_statuses.status_id', [$from->id, $into->id])->distinct()->pluck('columns.project_id');
            $keys = Project::whereKey($projectIds)->orderBy('id')->lockForUpdate()->pluck('key', 'id');
            $rows = DB::table('column_statuses')->join('columns', 'columns.id', '=', 'column_statuses.column_id')
                ->whereIn('column_statuses.status_id', [$from->id, $into->id])->lockForUpdate()->get(['columns.project_id', 'columns.id as column_id', 'column_statuses.status_id', 'column_statuses.position']);
            $apart = $rows->groupBy('project_id')->filter(fn ($r) => $r->pluck('status_id')->unique()->count() === 2 && $r->pluck('column_id')->unique()->count() > 1);
            if ($apart->isNotEmpty()) {
                $apartKeys = $apart->keys()->map(fn ($id) => $keys[$id])->sort()->implode(', ');
                throw ValidationException::withMessages(['status' => "{$from->name} and {$into->name} are in different columns of {$apartKeys}. Put them in one column there first."]);
            }

            foreach ($rows->where('status_id', $from->id) as $row) {
                $kept = $rows->where('column_id', $row->column_id)->firstWhere('status_id', $into->id);
                if ($kept) {
                    // Both in one column: the survivor takes the earlier position.
                    DB::table('column_statuses')->where(['column_id' => $row->column_id, 'status_id' => $into->id])->update(['position' => min($kept->position, $row->position)]);
                    DB::table('column_statuses')->where(['column_id' => $row->column_id, 'status_id' => $from->id])->delete();
                } else {
                    DB::table('column_statuses')->where(['column_id' => $row->column_id, 'status_id' => $from->id])->update(['status_id' => $into->id]);
                }
            }

            // shortcut: no version bump or per-issue activity row for the moved issues (like a label rename), one audit event; add them if merges must trigger edit conflicts.
            // The query builder has no SoftDeletes scope, so deleted issues move too.
            $moved = DB::table('issues')->where('status_id', $from->id)->update(['status_id' => $into->id]);
            IssueStatusHistory::where('to_status_id', $from->id)->update(['to_status_id' => $into->id]);
            IssueStatusHistory::where('from_status_id', $from->id)->update(['from_status_id' => $into->id]);
            // Pending requests only; decided ones keep what they said (to_status names it even after the status goes).
            // shortcut: one update per pending request, fine while a workspace has few; bulk it past a few thousand.
            $requests->each(function ($request) use ($from, $into) {
                $action = $request->action;
                foreach (['to_status_id', 'from_status_id'] as $key) {
                    if (($action[$key] ?? null) === $from->id) {
                        $action[$key] = $into->id;
                        $key === 'to_status_id' && $action['to_status'] = $into->name;
                    }
                }
                $request->update(['action' => $action]);
            });

            $from->delete();
            $this->audit->log('status.merged', $into, ['from' => $from->name, 'into' => $into->name, 'issues' => $moved], $workspace, $actor);

            return $moved;
        });
    }

    /** Unmap $status from $column; the caller holds the locks. A column keeps one status, and a status issues of the project use stays. */
    public function detach(Column $column, Status $status): void
    {
        $project = $column->loadMissing('project')->project;
        $mapped = $column->statuses()->get();
        if (! $mapped->contains('id', $status->id)) {
            throw ValidationException::withMessages(['status' => "{$status->name} is not in the {$column->name} column of {$project->key}. It has: ".$mapped->pluck('name')->implode(', ').'.'])->status(404);
        }
        if ($mapped->count() === 1) {
            throw ValidationException::withMessages(['status' => "The {$column->name} column needs at least one status. Add another before removing {$status->name}."]);
        }
        $issues = $project->issues()->withTrashed()->where('status_id', $status->id)->count();
        if ($issues) {
            throw ValidationException::withMessages(['status' => "{$status->name} is used by {$issues} ".str('issue')->plural($issues)." in {$project->key}. Move them to another status first."]);
        }
        $column->statuses()->detach($status->id);
    }

    /** Every status of $workspace by name, with projects_count (projects mapping it) and issues_count (soft-deleted issues included). */
    public function usage(Workspace $workspace): Collection
    {
        $issues = DB::table('issues')->join('projects', 'projects.id', '=', 'issues.project_id')->where('projects.workspace_id', $workspace->id)
            ->groupBy('issues.status_id')->selectRaw('issues.status_id, count(*) as n')->pluck('n', 'status_id');
        $projects = DB::table('column_statuses')->join('columns', 'columns.id', '=', 'column_statuses.column_id')->join('projects', 'projects.id', '=', 'columns.project_id')
            ->where('projects.workspace_id', $workspace->id)->groupBy('column_statuses.status_id')->selectRaw('column_statuses.status_id, count(distinct columns.project_id) as n')->pluck('n', 'status_id');

        return $workspace->statuses()->orderBy('name')->get()->each(fn ($s) => $s->forceFill(['projects_count' => (int) ($projects[$s->id] ?? 0), 'issues_count' => (int) ($issues[$s->id] ?? 0)]));
    }

    /** One transaction under the workspace row lock (the order is workspace, project, issue), with $status re-read fresh. */
    private function locked(Status $status, callable $run): mixed
    {
        return DB::transaction(function () use ($status, $run) {
            $workspace = Workspace::whereKey($status->workspace_id)->lockForUpdate()->firstOrFail();
            $fresh = Status::whereKey($status->id)->first()
                ?? throw ValidationException::withMessages(['status' => "{$status->name} no longer exists."])->status(404);

            return $run($fresh, $workspace);
        });
    }
}
