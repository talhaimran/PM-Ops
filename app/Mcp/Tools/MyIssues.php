<?php

namespace App\Mcp\Tools;

use App\Http\Resources\IssueCardResource;
use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\DashboardService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('my_issues')]
#[Description('Open issues assigned to you across all projects, highest priority and soonest due first.')]
#[IsReadOnly]
class MyIssues extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, DashboardService $dashboard): Response
    {
        $data = $request->validate(['limit' => 'nullable|integer|min:1|max:50', 'fields' => 'nullable|string|max:500']);
        $limit = $data['limit'] ?? 25;
        $issues = $dashboard->myIssues($request->user(), $limit);

        return $this->result(
            "You have {$issues->count()} open assigned ".Str::plural('issue', $issues->count()).($issues->count() === $limit ? " (showing the first {$limit})" : '').'.',
            IssueCardResource::collectionWith($issues, $data['fields'] ?? null)->resolve(),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(50)->description('Default 25.'),
            'fields' => $schema->string()->description('Comma-separated keys to return, e.g. "title,status"; key is always included.'),
        ];
    }
}
