<?php

namespace App\Mcp\Tools;

use App\Http\Resources\ActivityResource;
use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\IssueService;
use App\Services\Pagination;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_issue_activity')]
#[Description('Change history of an issue, newest first: who changed what, when, and via web, api or mcp.')]
#[IsReadOnly]
class GetIssueActivity extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, IssueService $issues): Response
    {
        $data = $request->validate(['issue' => 'required|string', 'page' => 'integer|min:1|max:10000', 'per_page' => 'integer|min:1|max:50', 'cursor' => 'nullable|string|max:100']);
        $issue = $issues->resolve($request->user(), $data['issue']);
        [$perPage, $pageNumber] = Pagination::resolve($data['cursor'] ?? null, $data['per_page'] ?? null, $data['page'] ?? null, max: 50);
        $page = $issue->activities()->with(['user', 'agent.owner'])->latest('id')->paginate($perPage, page: $pageNumber);

        return $this->paginated("activity entries for {$issue->key}", $page, ActivityResource::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->string()->description('Issue key, e.g. WEB-12.')->required(),
            'page' => $schema->integer()->min(1)->max(10000),
            'cursor' => $schema->string()->description('next_cursor from the previous page; use instead of page.'),
            'per_page' => $schema->integer()->min(1)->max(50)->description('Items per page, default 25, max 50.'),
        ];
    }
}
