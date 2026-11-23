<?php

namespace App\Services;

use App\Enums\StatusCategory;
use App\Models\Column;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

/** Spec 7.4: the workspace status library and every name lookup into it. */
class StatusService
{
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
     * or a column name when that column has exactly one status. $firstOfColumn (a web board drop) takes the
     * column's first status however many it has.
     */
    public function resolveFor(Project $project, string $name, bool $firstOfColumn = false): Status
    {
        $mapped = $project->statuses()->get();
        $status = $mapped->first(fn ($s) => strcasecmp($s->name, trim($name)) === 0);

        if (! $status) {
            $inColumn = $mapped->filter(fn ($s) => strcasecmp($s->column_name, trim($name)) === 0);
            $status = $firstOfColumn || $inColumn->count() === 1 ? $inColumn->first() : null;
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
}
