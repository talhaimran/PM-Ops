<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\ColumnStatusRequest;
use App\Http\Requests\Workspaces\MergeStatusRequest;
use App\Http\Requests\Workspaces\UpdateStatusRequest;
use App\Models\Column;
use App\Models\Project;
use App\Services\ColumnService;
use App\Services\CurrentWorkspace;
use App\Services\StatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Statuses (spec 7.4, Owners and Admins, web only) and the column editor's status mapping (project Admins,
 * people only). Statuses are created from the column editor, so there is no create route here (1-R12).
 */
class StatusController extends Controller
{
    public function __construct(private CurrentWorkspace $current, private StatusService $statuses, private ColumnService $columns) {}

    public function index(): Response
    {
        $workspace = $this->current->get();
        Gate::authorize('manageStatuses', $workspace);

        return Inertia::render('Workspaces/Settings/Statuses', [
            'statuses' => $this->statuses->usage($workspace)->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'category' => $s->category->value, 'color' => $s->color,
                'projects_count' => $s->projects_count, 'issues_count' => $s->issues_count,
            ])->values(),
            'palette' => StatusService::PALETTE,
        ]);
    }

    public function update(UpdateStatusRequest $request, int $status): RedirectResponse
    {
        $workspace = $this->current->get();
        Gate::authorize('manageStatuses', $workspace);
        $status = $this->statuses->find($workspace, $status);
        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $this->statuses->rename($status, $request->user(), $data['name']);
        }
        if (array_key_exists('color', $data)) {
            $this->statuses->recolour($status, $request->user(), $data['color']);
        }

        return back();
    }

    public function merge(MergeStatusRequest $request, int $status): RedirectResponse
    {
        $workspace = $this->current->get();
        Gate::authorize('manageStatuses', $workspace);
        $from = $this->statuses->find($workspace, $status);
        $this->statuses->merge($from, $request->validated('into'), $request->user());

        return back();
    }

    public function destroy(int $status): RedirectResponse
    {
        $workspace = $this->current->get();
        Gate::authorize('manageStatuses', $workspace);
        $this->statuses->delete($this->statuses->find($workspace, $status), auth()->user());

        return back();
    }

    public function attach(ColumnStatusRequest $request, Project $project, Column $column): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $this->columns->addStatus($column->setRelation('project', $project), $request->user(), $request->validated());

        return back();
    }

    public function detach(Project $project, Column $column, int $status): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $this->columns->removeStatus($column->setRelation('project', $project), $this->statuses->find($this->current->get(), $status));

        return back();
    }
}
