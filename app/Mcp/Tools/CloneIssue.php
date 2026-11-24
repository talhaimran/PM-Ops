<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Issues\CloneIssueRequest;
use App\Http\Resources\IssueResource;
use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\IssueService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('clone_issue')]
#[Description('Clone an issue in the same project: copies title, description, type, labels and story points into a new To Do issue (unassigned, no sprint, epic or dates) linked "clone clones original". subtasks: true clones its sub-tasks; links: true copies its blocks, precedes and relates to links you can see. Comments and history are never copied. Needs Write level.')]
class CloneIssue extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, IssueService $issues): Response
    {
        $request->validate(['key' => 'required|string|max:30']);
        $original = $issues->resolve($request->user(), $request->get('key'));
        Gate::authorize('update', $original);
        $data = $request->validate((new CloneIssueRequest)->rules());
        $clone = $issues->clone($original, $request->user(), $data['subtasks'] ?? false, $data['links'] ?? false);

        return $this->result("Cloned {$original->key} as {$clone->key}.", new IssueResource($clone));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Issue key to clone, e.g. WEB-12.')->required(),
            'subtasks' => $schema->boolean()->description('Also clone its sub-tasks under the clone. Default false.'),
            'links' => $schema->boolean()->description('Also copy its blocks, precedes and relates to links (only to issues you can see). Default false.'),
        ];
    }
}
