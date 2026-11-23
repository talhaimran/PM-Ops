<?php

namespace App\Services;

use App\Enums\StatusCategory;
use App\Models\Column;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ColumnService
{
    public function __construct(private StatusService $statuses, private ActivityLogger $activity, private HistoryRecorder $history) {}

    /**
     * 1-R4: the column maps `status` (a library status name) when given, else a status named after the column in
     * `category` (default in progress), reused or created.
     *
     * @param  array{name: string, position?: ?int, status?: ?string, category?: ?string}  $data
     */
    public function create(Project $project, User $actor, array $data): Column
    {
        $this->ensureUnique($project, $data['name']);

        return DB::transaction(function () use ($project, $actor, $data) {
            // The project row first, the same order as moves and approvals; it also keeps a status in one column.
            Project::whereKey($project->id)->lockForUpdate()->first();
            $columns = $project->columns()->lockForUpdate()->get();
            $workspace = $project->workspace()->firstOrFail();
            $status = isset($data['status'])
                ? $this->statuses->named($workspace, $data['status'])
                : $this->statuses->forColumn($workspace, $data['name'], StatusCategory::from($data['category'] ?? StatusCategory::InProgress->value), $project->key, $actor);
            $column = $project->columns()->create(['name' => trim($data['name']), 'position' => $columns->count()]);
            $this->statuses->attach($column->setRelation('project', $project), $status);
            $this->resequence($columns, $column, $data['position'] ?? $columns->count());

            return $column->load('statuses');
        });
    }

    /** @param array{name?: string, position?: ?int} $data */
    public function update(Column $column, array $data): Column
    {
        $project = $column->project;

        if (isset($data['name'])) {
            $this->ensureUnique($project, $data['name'], $column);
        }

        return DB::transaction(function () use ($project, $column, $data) {
            if (isset($data['name'])) {
                $column->update(['name' => trim($data['name'])]);
            }
            if (isset($data['position'])) {
                $others = $project->columns()->lockForUpdate()->get()->reject(fn ($c) => $c->is($column));
                $this->resequence($others, $column, $data['position']);
            }

            return $column->refresh()->load('statuses');
        });
    }

    /** Delete a column; its issues move to the first status of $moveTo (required when it has any). Its statuses stay in the library. */
    public function delete(Column $column, ?string $moveTo, ?User $actor = null): void
    {
        $project = $column->project;
        $others = $project->columns()->get()->reject(fn ($c) => $c->is($column))->values();

        if ($others->isEmpty()) {
            throw ValidationException::withMessages(['column' => "{$project->key} needs at least one column; add another before deleting \"{$column->name}\"."]);
        }

        $count = $column->issues()->count();
        $target = null;

        if ($count > 0) {
            $target = $moveTo ? $others->first(fn ($c) => strcasecmp($c->name, trim($moveTo)) === 0) : null;
            if (! $target) {
                throw ValidationException::withMessages([
                    'move_issues_to' => "\"{$column->name}\" has {$count} issues. Pass move_issues_to with one of: ".$others->pluck('name')->implode(', ').'.',
                ]);
            }
        }

        DB::transaction(function () use ($column, $target, $others, $actor) {
            // The project row first, the same order as moves and approvals (CLAUDE.md, ApprovalService).
            Project::whereKey($column->project_id)->lockForUpdate()->first();
            // Soft-deleted issues move too (to the first column when no target was needed), so they can still be restored.
            $to = ($target ?? $others->sortBy('position')->first())->statuses()->firstOrFail();
            // Spec 7.4: the moved issues keep the rule too. Into Done they are resolved (a resolution they have stays); out of it they are reopened.
            $moving = Issue::withTrashed()->where('project_id', $column->project_id)->whereIn('status_id', $column->statuses()->pluck('statuses.id'));
            // Every moved issue gets a history row (bulk-inserted per chunk); issues leaving Done are also logged as reopened, like any other move out of it.
            // shortcut: one activity row (and model) per reopened issue inside the lock, chunk and bulk-insert when Done columns hold thousands.
            $reopenedIds = [];
            (clone $moving)->join('statuses', 'statuses.id', '=', 'issues.status_id')->select('issues.id', 'issues.status_id', 'statuses.category')
                ->chunkById(500, function ($rows) use ($to, $actor, &$reopenedIds) {
                    $this->history->statusesMoved($rows, $to, $actor);
                    if (! $to->isDone()) {
                        array_push($reopenedIds, ...$rows->where('category', StatusCategory::Done->value)->pluck('id')->all());
                    }
                }, 'issues.id', 'id');
            $reopened = $reopenedIds ? Issue::withTrashed()->with('status')->whereIn('id', $reopenedIds)->get() : collect();
            $moving->update(['status_id' => $to->id] + ($to->isDone()
                ? ['resolution' => DB::raw("coalesce(resolution, 'done')"), 'resolved_at' => DB::raw("coalesce(resolved_at, '".now()->toDateTimeString()."')")]
                : ['resolution' => null, 'resolved_at' => null]));
            foreach ($reopened as $issue) {
                $this->activity->log($issue, $actor, 'reopened', ['status' => ['from' => $issue->status->name, 'to' => $to->name], 'resolution' => ['from' => $issue->resolution?->value, 'to' => null]]);
            }
            $column->delete();
            $this->resequence($others);
        });
    }

    /** Find a column by name (case-insensitive); 404 lists the project's columns. */
    public function resolve(Project $project, string $name): Column
    {
        $columns = $project->columns()->get();
        $column = $columns->first(fn ($c) => strcasecmp($c->name, trim($name)) === 0);

        if (! $column) {
            throw ValidationException::withMessages([
                'column' => "Column \"{$name}\" was not found in {$project->key}. Columns: ".$columns->pluck('name')->implode(', ').'.',
            ])->status(404);
        }

        return $column->setRelation('project', $project);
    }

    /** Write positions 0..n, optionally inserting $insert at $position. */
    private function resequence(Collection $columns, ?Column $insert = null, ?int $position = null): void
    {
        $ordered = $columns->sortBy('position')->values();

        if ($insert) {
            $ordered->splice(max(0, min($position, $ordered->count())), 0, [$insert]);
        }

        foreach ($ordered as $i => $column) {
            if ($column->position !== $i || $column->is($insert)) {
                $column->update(['position' => $i]);
            }
        }
    }

    private function ensureUnique(Project $project, string $name, ?Column $except = null): void
    {
        // Case-insensitive via MySQL collation.
        if ($project->columns()->where('name', trim($name))->when($except, fn ($q) => $q->whereKeyNot($except->id))->exists()) {
            throw ValidationException::withMessages(['name' => "{$project->key} already has a column named \"{$name}\"."]);
        }
    }
}
