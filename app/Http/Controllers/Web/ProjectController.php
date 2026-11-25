<?php

namespace App\Http\Controllers\Web;

use App\Enums\ProjectRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DestroyProjectRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ColumnResource;
use App\Http\Resources\LabelResource;
use App\Http\Resources\ProjectInvitationResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\StatusResource;
use App\Models\Project;
use App\Models\WorkspaceInvitation;
use App\Services\AiSettingsService;
use App\Services\CurrentWorkspace;
use App\Services\IssueService;
use App\Services\ProjectService;
use App\Services\WorkspaceMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $projects) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Projects/Index', [
            'projects' => ProjectResource::collection($user->accessibleProjects()->orderBy('key')->get())->resolve(),
            'invitations' => ProjectInvitationResource::collection(
                // Only to a verified address: anyone can type an email they don't own.
                $user->hasVerifiedEmail() ? WorkspaceInvitation::with('project')->where('email', strtolower($user->email))->latest()->get() : []
            )->resolve(),
            'can' => [
                'create' => $user->can('create', Project::class),
                // Owners and Admins see every project; ones they aren't in show "Join as Admin" (spec 7.0b).
                'join' => $user->can('viewAllProjects', app(CurrentWorkspace::class)->get()),
            ],
        ]);
    }

    public function join(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('join', $project);
        app(WorkspaceMemberService::class)->joinProjectAsAdmin($project, $request->user());

        return redirect()->route('projects.show', $project->key);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        $project = $this->projects->create($request->user(), $request->validated());

        return redirect()->route('projects.settings', $project->key);
    }

    /** The members the composer's @ list offers: the project's, only to someone who can see the project. */
    public function mentionable(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['members' => $project->members()->orderBy('name')->get(['users.name', 'users.email'])->map(fn ($u) => ['name' => $u->name, 'email' => $u->email])->all()]);
    }

    public function settings(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        $project->load(['members' => fn ($q) => $q->orderBy('name'), 'invitations', 'agentSettings']);
        $canManage = $request->user()->can('manage', $project);
        $workspace = app(CurrentWorkspace::class)->get();

        return Inertia::render('Projects/Settings', [
            'project' => (new ProjectResource($project))->resolve(),
            'invitations' => $canManage ? ProjectInvitationResource::collection($project->invitations)->resolve() : [],
            'roles' => ProjectRole::values(),
            // A5: Mode C runs only when all three are on; the page says which one is off.
            'modeC' => [
                'project' => (bool) ($project->agentSettings?->mode_c_enabled ?? true),
                'workspace' => (bool) $workspace->setting('mode_c_enabled'),
                'provider' => app(AiSettingsService::class)->for($workspace) !== null,
            ],
            'labels' => LabelResource::collection($project->labels()->get())->resolve(),
            // Each column's statuses carry their id (the column editor removes by id); the library is what can be added.
            'columns' => $project->columns()->with('statuses')->withCount('issues')->get()->map(fn ($c) => [
                ...(new ColumnResource($c))->resolve(),
                'statuses' => $c->statuses->map(fn ($s) => ['id' => $s->id, ...(new StatusResource($s))->resolve()])->all(),
            ])->all(),
            'library' => $canManage ? $workspace->statuses()->orderBy('name')->get()->map(fn ($s) => ['id' => $s->id, ...(new StatusResource($s))->resolve()])->all() : [],
            // Admin-only (spec 5.1): restore from here until issues:purge removes them after 30 days.
            'deletedIssues' => $canManage ? app(IssueService::class)->deleted($project) : [],
            'can' => ['manage' => $canManage, 'contribute' => $request->user()->can('contribute', $project)],
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->projects->update($project, $request->validated());

        return back();
    }

    public function destroy(DestroyProjectRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);
        $this->projects->delete($project);

        return redirect()->route('projects.index');
    }
}
