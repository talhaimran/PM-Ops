<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\ColumnRequest;
use App\Http\Requests\Board\DeleteColumnRequest;
use App\Http\Resources\ColumnResource;
use App\Services\ColumnService;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ColumnController extends Controller
{
    public function __construct(private ProjectService $projects, private ColumnService $columns) {}

    /** Board columns in order, each with the statuses it shows and its issue count. */
    public function index(Request $request, string $key): AnonymousResourceCollection
    {
        $project = $this->projects->resolve($request->user(), $key);

        return ColumnResource::collection($project->columns()->with('statuses')->withCount('issues')->get());
    }

    /**
     * Add a column (admins). Without `position` it is appended. `status` maps an existing library status; without it,
     * a status named after the column in `category` (to_do, in_progress (default) or done) is reused or created.
     */
    public function store(ColumnRequest $request, string $key): ColumnResource
    {
        $project = $this->projects->resolve($request->user(), $key);
        Gate::authorize('manage', $project);

        return new ColumnResource($this->columns->create($project, $request->user(), $request->validated()));
    }

    /** Rename and/or move a column, addressed by its current name (admins). Its statuses keep their names. */
    public function update(ColumnRequest $request, string $key, string $name): ColumnResource
    {
        $project = $this->projects->resolve($request->user(), $key);
        Gate::authorize('manage', $project);

        return new ColumnResource($this->columns->update($this->columns->resolve($project, $name), $request->validated()));
    }

    /** Delete a column (admins). If it has issues, `move_issues_to` must name another column; they take its first status. */
    public function destroy(DeleteColumnRequest $request, string $key, string $name): Response
    {
        $project = $this->projects->resolve($request->user(), $key);
        Gate::authorize('removeColumn', $project);
        $this->columns->delete($this->columns->resolve($project, $name), $request->validated('move_issues_to'));

        return response()->noContent();
    }
}
