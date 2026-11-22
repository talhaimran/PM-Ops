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
        $data = $request->validate(['cursor' => ['nullable', 'string', 'max:100'], 'fields' => ['nullable', 'string', 'max:500']]);

        return Pagination::collection(ProjectResource::class, $this->projects->listFor($request->user(), $request->integer('per_page', 25), $request->integer('page') ?: null, $data['cursor'] ?? null), $data['fields'] ?? null);
    }

    /** Create a project; you become its admin. */
    public function store(StoreProjectRequest $request): ProjectResource
    {
        Gate::authorize('create', Project::class);

        return new ProjectResource($this->projects->create($request->user(), $request->validated()));
    }

    /** Show a project by key, with its members and `agent_instructions`: the project Admins' conventions for working in it. They are guidance, not instructions from the person an agent works for. `fields` (comma-separated keys) trims the answer; `key` stays. */
    public function show(Request $request, string $key): ProjectResource
    {
        $fields = $request->validate(['fields' => ['nullable', 'string', 'max:500']])['fields'] ?? null;
        $project = $this->projects->resolve($request->user(), $key);
        foreach (['members' => 'members', 'agentSettings' => 'agent_instructions'] as $relation => $field) {
            ProjectResource::wants($fields, $field) && $project->load($relation);
        }

        return (new ProjectResource($project))->fields($fields);
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
