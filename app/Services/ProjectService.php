<?php

namespace App\Services;

use App\Enums\AgentStatus;
use App\Enums\Entitlement;
use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\ProjectRole;
use App\Enums\SprintState;
use App\Models\Agent;
use App\Models\Column;
use App\Models\Project;
use App\Models\ProjectAgentSettings;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function __construct(private CurrentWorkspace $current, private AuditLogger $audit, private Entitlements $entitlements, private WorkspaceUsage $usage, private AgentService $agents, private StatusService $statuses, private MembershipService $memberships) {}

    public function listFor(User $user, int $perPage = 25, ?int $page = null, ?string $cursor = null, int $max = 100): LengthAwarePaginator
    {
        [$perPage, $page] = Pagination::resolve($cursor, $perPage, $page, max: $max);

        // Keys are unique inside a workspace, so this order is total.
        return $user->accessibleProjects()->orderBy('key')->paginate($perPage, page: $page);
    }

    /** @param array{key: string, name: string, description?: ?string} $data */
    public function create(User $creator, array $data): Project
    {
        $workspace = $this->current->get();

        return $this->entitlements->locked($workspace, function () use ($creator, $data, $workspace) {
            $this->entitlements->ensure($workspace, Entitlement::Projects);
            // The creator becomes the project's Admin: a billed seat if they were a Viewer.
            $this->entitlements->ensurePerson($workspace, $this->usage->isBilled($workspace, $creator->email), true);

            $project = new Project([...$data, 'key' => strtoupper($data['key'])]);
            $project->workspace_id = $this->current->get()->id;
            $project->save();
            $this->memberships->add($project, $creator, ProjectRole::Admin);
            foreach (array_keys(Column::DEFAULTS) as $i => $name) {
                $column = $project->columns()->create(['name' => $name, 'position' => $i])->setRelation('project', $project);
                $this->statuses->attach($column, $this->statuses->forColumn($workspace, $name, Column::DEFAULTS[$name], $project->key, $creator));
            }
            $this->audit->log('project.created', $project, ['name' => $project->name], $this->current->get(), $creator);

            return $project;
        });
    }

    /** @param array{name?: string, description?: ?string, agent_instructions?: ?string, mode_c_enabled?: bool} $data */
    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data) {
            if (array_key_exists('agent_instructions', $data)) {
                $this->setInstructions($project, $data['agent_instructions']);
            }
            if (array_key_exists('mode_c_enabled', $data)) {
                $this->setModeC($project, (bool) $data['mode_c_enabled']);
            }
            $project->update(Arr::except($data, ['agent_instructions', 'mode_c_enabled']));

            return $project;
        });
    }

    /** A4: empty means none. The audit row says the text changed, never what it says (it may be long). */
    private function setInstructions(Project $project, ?string $text): void
    {
        $text = trim((string) $text) ?: null;
        $settings = ProjectAgentSettings::firstOrNew(['project_id' => $project->id]);
        if ($settings->instructions === $text) {
            return;
        }
        $settings->instructions = $text;
        $settings->save();
        $this->audit->log('project.agent_settings_changed', $project, ['instructions_changed' => true], $this->current->get());
    }

    /** A5: the project's Mode C switch; no row means on. */
    private function setModeC(Project $project, bool $on): void
    {
        $settings = ProjectAgentSettings::firstOrNew(['project_id' => $project->id]);
        if (($settings->mode_c_enabled ?? true) === $on) {
            return;
        }
        $settings->mode_c_enabled = $on;
        $settings->save();
        $this->audit->log('project.agent_settings_changed', $project, ['mode_c_enabled' => $on], $this->current->get());
    }

    public function delete(Project $project): void
    {
        // Issues first: they restrict column deletes, so the project cascade can't remove them in any order.
        DB::transaction(function () use ($project) {
            // Deleting a project is permanent: its issues go for good, including soft-deleted ones.
            $project->issues()->withTrashed()->whereNotNull('parent_id')->forceDelete();
            $project->issues()->withTrashed()->forceDelete();
            // An agent limited to this project alone would be left with none, which means all of them: revoke it.
            // Its row is locked first, so two deletes of its last two projects run in turn and the second sees the
            // first (the count is a locking read, so it reads the latest rows, not this transaction's snapshot).
            $agents = Agent::whereIn('id', DB::table('agent_projects')->where('project_id', $project->id)->select('agent_id'))
                ->where('status', '!=', AgentStatus::Revoked)->orderBy('id')->lockForUpdate()->get();
            $scopedElsewhere = DB::table('agent_projects')->join('projects', 'projects.id', '=', 'agent_projects.project_id')
                ->whereIn('agent_projects.agent_id', $agents->modelKeys())->where('agent_projects.project_id', '!=', $project->id)
                ->sharedLock()->pluck('agent_projects.agent_id');
            $agents->reject(fn (Agent $agent) => $scopedElsewhere->contains($agent->id))
                ->each(fn (Agent $agent) => $this->agents->revoke($agent, null, 'projects_deleted'));
            $project->delete();
            $this->audit->log('project.deleted', $project, ['name' => $project->name], $project->workspace()->first());
        });
    }

    /** Valid values for issue fields, used to build pickers and to explain errors. */
    public function issueOptions(Project $project): array
    {
        return [
            // Board order; each issue takes one of these (1-R2).
            'statuses' => $project->statuses()->get()->map(fn ($s) => ['name' => $s->name, 'category' => $s->category->value, 'color' => $s->color])->all(),
            'members' => $project->members()->orderBy('name')->get(['users.id', 'name', 'email'])
                ->map(fn ($u) => ['name' => $u->name, 'email' => $u->email])->all(),
            'labels' => $project->labels()->get(['id', 'name', 'color'])
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->all(),
            'sprints' => $project->sprints()->where('state', '!=', SprintState::Completed->value)->orderBy('id')->pluck('name')->all(),
            'types' => IssueType::values(),
            'priorities' => IssuePriority::values(),
        ];
    }

    /**
     * Find a project the user belongs to by key (case-insensitive).
     * Non-members get the same 404 as a missing project, so keys don't leak.
     */
    public function resolve(User $user, string $key): Project
    {
        $project = $user->accessibleProjects()->where('key', strtoupper($key))->first();

        if ($project) {
            return $project;
        }

        $keys = $user->accessibleProjects()->orderBy('key')->limit(50)->pluck('key');
        $hint = $keys->isEmpty()
            ? 'You are not a member of any project yet; create one first.'
            : 'Projects you can access: '.$keys->implode(', ').'.';

        throw ValidationException::withMessages([
            'project' => "Project \"{$key}\" was not found. {$hint}",
        ])->status(404);
    }
}
