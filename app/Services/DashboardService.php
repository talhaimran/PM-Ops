<?php

namespace App\Services;

use App\Enums\SprintState;
use App\Enums\StatusCategory;
use App\Models\Column;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(private IssueService $issues) {}

    /**
     * Issues assigned to the user across their projects: priority desc, due date (nulls last), key. Not done unless
     * $filters (category, resolution) say what to show instead.
     */
    public function myIssues(User $user, int $limit = 50, array $filters = []): Collection
    {
        $query = Issue::whereIn('project_id', $user->accessibleProjects()->select('projects.id'))
            ->where('assignee_id', $user->id)
            ->when(
                $filters['category'] ?? $filters['resolution'] ?? null,
                fn ($q) => $this->issues->filter($q, Arr::only($filters, ['category', 'resolution'])),
                fn ($q) => $q->open(),
            )
            ->with(IssueService::DISPLAY);

        return $query
            ->orderByRaw("FIELD(priority, 'lowest', 'low', 'medium', 'high', 'highest') desc")
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('project_id')
            ->orderBy('number')
            ->limit($limit)
            ->get();
    }

    /**
     * Per-project status counts, type counts, open/overdue counts and active sprint progress,
     * computed with a fixed number of queries regardless of how many projects there are.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array> keyed by project id
     */
    public function summaries(Collection $projects): array
    {
        $ids = $projects->pluck('id');
        $columns = Column::whereIn('project_id', $ids)->with('statuses')->orderBy('position')->get()->groupBy('project_id');
        $byStatus = Issue::whereIn('issues.project_id', $ids)->join('statuses', 'statuses.id', '=', 'issues.status_id')
            ->selectRaw('issues.project_id, issues.status_id, statuses.category, count(*) as total')
            ->groupBy('issues.project_id', 'issues.status_id', 'statuses.category')->get()->groupBy('project_id');
        $byType = Issue::whereIn('project_id', $ids)
            ->selectRaw('project_id, type, count(*) as total')->groupBy('project_id', 'type')->get()->groupBy('project_id');
        $overdue = Issue::whereIn('project_id', $ids)
            ->open()
            ->whereDate('due_date', '<', today())
            ->selectRaw('project_id, count(*) as total')->groupBy('project_id')->pluck('total', 'project_id');

        $sprints = Sprint::whereIn('project_id', $ids)
            ->where('state', SprintState::Active->value)
            ->withCount(['issues', 'issues as issues_done' => fn ($q) => $q->completed()])
            ->withSum('issues as points_total', 'story_points')
            ->withSum(['issues as points_done' => fn ($q) => $q->completed()], 'story_points')
            ->get()
            ->keyBy('project_id');

        return $projects->mapWithKeys(function ($project) use ($columns, $byStatus, $byType, $overdue, $sprints) {
            $counts = $byStatus[$project->id] ?? collect();
            // Per board column, as the board shows them.
            $statuses = ($columns[$project->id] ?? collect())
                ->map(fn ($c) => ['name' => $c->name, 'count' => (int) $counts->whereIn('status_id', $c->statuses->modelKeys())->sum('total')])->values();
            $sprint = $sprints[$project->id] ?? null;

            return [$project->id => [
                'statuses' => $statuses->all(),
                'types' => ($byType[$project->id] ?? collect())->mapWithKeys(fn ($r) => [$r->type->value => (int) $r->total])->all(),
                'total' => (int) $counts->sum('total'),
                'open' => (int) $counts->where('category', '!=', StatusCategory::Done->value)->sum('total'),
                'overdue' => (int) ($overdue[$project->id] ?? 0),
                'active_sprint' => $sprint ? [
                    'name' => $sprint->name,
                    'start_date' => $sprint->start_date?->format('Y-m-d'),
                    'end_date' => $sprint->end_date?->format('Y-m-d'),
                    'issue_count' => (int) $sprint->issues_count,
                    'issues_done' => (int) $sprint->issues_done,
                    'points_total' => (int) $sprint->points_total,
                    'points_done' => (int) $sprint->points_done,
                ] : null,
            ]];
        })->all();
    }
}
