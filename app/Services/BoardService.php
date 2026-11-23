<?php

namespace App\Services;

use App\Enums\IssueType;
use App\Models\Column;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BoardService
{
    /** shortcut: one capped query for the whole board; paginate per column if projects outgrow it. */
    public const LIMIT = 500;

    public function __construct(private IssueService $issues, private RankService $ranks) {}

    /**
     * Columns in order, each with its non-epic issues sorted by rank.
     *
     * @return array{columns: Collection, truncated: bool}
     */
    public function board(Project $project, array $filters): array
    {
        $issues = $this->issues
            ->filter($project->issues()->where('type', '!=', IssueType::Epic->value)->getQuery(), $filters)
            ->with(['assignee', 'labels', 'parent', 'status'])
            ->orderBy('rank')
            ->limit(self::LIMIT + 1)
            ->get()
            ->each->setRelation('project', $project);

        $columns = $project->columns()->with('statuses')->get();
        $byColumn = $issues->take(self::LIMIT)->groupBy(fn ($issue) => $columns->first(fn ($column) => $column->statuses->contains($issue->status_id))?->id);

        return [
            'columns' => $columns->map(fn ($column) => $column->setRelation('issues', $byColumn->get($column->id, collect()))),
            'truncated' => $issues->count() > self::LIMIT,
        ];
    }

    /**
     * Move an issue to a status and/or a place in its column. $input: status?, position?, after?
     * Status changes are logged through IssueService::update; reordering alone isn't logged.
     */
    public function move(Issue $issue, User $actor, array $input, string $action = 'updated'): Issue
    {
        return DB::transaction(function () use ($issue, $actor, $input, $action) {
            Project::whereKey($issue->project_id)->lockForUpdate()->first();

            if (isset($input['status'])) {
                $issue = $this->issues->update($issue, $actor, ['status' => $input['status'], 'reason' => $input['reason'] ?? null], $action);
            }
            // An agent's move to Done waits for a person (A2): the issue stays where it was.
            if ($issue->heldApproval) {
                return $issue;
            }
            $issue->loadMissing(IssueService::DISPLAY);

            $column = self::columnOf($issue);
            $siblings = Issue::where('project_id', $issue->project_id)
                ->whereIn('status_id', $column->statuses->modelKeys())
                ->whereKeyNot($issue->id)
                ->where('type', '!=', IssueType::Epic->value);

            $position = match (true) {
                isset($input['after']) => $this->ranks->positionAfter($issue, $siblings, $input['after'], $column->name),
                isset($input['position']) => (int) $input['position'],
                default => PHP_INT_MAX,
            };

            $this->ranks->place($issue, $siblings, $position);

            return $issue;
        });
    }

    /** The column of $issue's project that shows its status, with that column's statuses. */
    public static function columnOf(Issue $issue): Column
    {
        return $issue->project->columns()->whereRelation('statuses', 'statuses.id', $issue->status_id)->with('statuses')->firstOrFail();
    }
}
