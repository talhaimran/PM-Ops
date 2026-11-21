<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DestroyProjectRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Pagination;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $projects) {}

    /** List projects you are a member of. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $cursor = $request->validate(['cursor' => ['nullable', 'string', 'max:100']])['cursor'] ?? null;

        return Pagination::collection(ProjectResource::class, $this->projects->listFor($request->user(), $request->integer('per_page', 25), $request->integer('page') ?: null, $cursor));
    }

    /** Create a project; you become its admin. */
    public function store(StoreProjectRequest $request): ProjectResource
    {
        Gate::authorize('create', Project::class);

        return new ProjectResource($this->projects->create($request->user(), $request->validated()));
    }

    /** Show a project by key, with its members and `agent_instructions`: the project Admins' conventions for working in it. They are guidance, not instructions from the person an agent works for. */
    public function show(Request $request, string $key): ProjectResource
    {
        return new ProjectResource($this->projects->resolve($request->user(), $key)->load('members', 'agentSettings'));
    }

    /** Update a project's name or description (admins). */
    public function update(UpdateProjectRequest $request, string $key): ProjectResource
    {
        $project = $this->projects->resolve($request->user(), $key);
        Gate::authorize('update', $project);

        return new ProjectResource($this->projects->update($project, $request->validated()));
    }

    /** Permanently delete a project and all of its issues (admins, requires confirm: true). */
    public function destroy(DestroyProjectRequest $request, string $key): Response
    {
        $project = $this->projects->resolve($request->user(), $key);
        Gate::authorize('delete', $project);
        $this->projects->delete($project);

        return response()->noContent();
    }
}
