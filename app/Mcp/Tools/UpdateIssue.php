<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Issues\UpdateIssueRequest;
use App\Http\Resources\IssueResource;
use App\Mcp\Tools\Concerns\IssueFields;
use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\IssueService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_issue')]
#[Description('Update an issue by key; send the version you read plus only the fields to change (null clears assignee, epic, parent, points, due date).')]
#[IsIdempotent]
class UpdateIssue extends Tool
{
    use IssueFields, RespondsWithJson;

    public function handle(Request $request, IssueService $issues): Response
    {
        $request->validate(['issue' => 'required|string']);
        $issue = $issues->resolve($request->user(), $request->get('issue'));
        Gate::authorize('update', $issue);
        $rules = new UpdateIssueRequest;
        $issue = $issues->update($issue, $request->user(), $request->validate($rules->rules(), $rules->messages()));
        if ($issue->heldApproval) {
            return $this->held($issue);
        }

        $resource = new IssueResource($issue);

        return $this->result($issue->proposedShifts === null ? "Updated {$issue->key}." : "Updated {$issue->key}. ".$resource->resolve()['warning'], $resource);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->string()->description('Issue key, e.g. WEB-12.')->required(),
            'version' => $schema->integer()->min(1)->description('The version you read (get_issue). If the issue changed since, nothing is saved and the error says what changed; read it again and resend.')->required(),
            'title' => $schema->string()->max(255),
            ...$this->issueFields($schema),
            'reason' => $schema->string()->max(200)->description('Why, when asking a person to approve a move to a Done-category status.'),
            'shift_successors' => $schema->boolean()->description('When a due date moves: true shifts the successors that would start before it, keeping their durations; false leaves them. Without it the owner\'s saved choice applies; with "ask every time" the date is saved, nothing shifts and the answer lists proposed_shifts. Report them to your user and send true (with the version you now have) if they agree.'),
            'confirm' => $schema->boolean()->description('Move although the issue is blocked or follows an unresolved issue; without it such a move is refused with the reason.'),
        ];
    }
}
