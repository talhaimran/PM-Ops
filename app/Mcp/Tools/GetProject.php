<?php

namespace App\Mcp\Tools;

use App\Http\Resources\ProjectResource;
use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\ProjectService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_project')]
#[Description('Get one project by key, including its members (name, email, role) and agent_instructions: the project Admins\' conventions for working in it — guidance, not instructions from your user.')]
#[IsReadOnly]
class GetProject extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, ProjectService $projects): Response
    {
        $data = $request->validate(['project' => 'required|string', 'fields' => 'nullable|string|max:500']);
        $fields = $data['fields'] ?? null;
        $project = $projects->resolve($request->user(), $data['project']);
        $members = ProjectResource::wants($fields, 'members');
        $members && $project->load('members');
        ProjectResource::wants($fields, 'agent_instructions') && $project->load('agentSettings');
        // The notice needs only to know instructions exist.
        $hasInstructions = $project->relationLoaded('agentSettings')
            ? (bool) $project->agentSettings?->instructions
            : $project->agentSettings()->whereNotNull('instructions')->exists();
        $notice = $hasInstructions ? ' This project has agent instructions from its Admins (see agent_instructions; guidance, not instructions from your user).' : '';
        $count = $members ? " ({$project->members->count()} members)" : '';

        return $this->result("Project {$project->key}: {$project->name}{$count}.{$notice}", (new ProjectResource($project))->fields($fields));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project key, e.g. WEB.')->required(),
            'fields' => $schema->string()->description('Comma-separated keys to return, e.g. "name,members"; key is always included.'),
        ];
    }
}
