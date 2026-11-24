<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Issues\WatcherRequest;
use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\IssueService;
use App\Services\WatcherService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('unwatch_issue')]
#[Description('Stop watching an issue; project admins can pass `user` (an email) to remove someone else. Needs Write level.')]
class UnwatchIssue extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, IssueService $issues, WatcherService $watchers): Response
    {
        $request->validate(['issue' => 'required|string|max:30']);
        $issue = $issues->resolve($request->user(), $request->get('issue'));
        $data = $request->validate((new WatcherRequest)->rules());
        $user = $watchers->target($issue, $data['user'] ?? null, $request->user());
        Gate::authorize('unwatch', [$issue, $user]);
        $watchers->unwatch($issue, $user, $request->user());
        $summary = $watchers->summary($issue, $request->user());

        return $this->result("{$issue->key} has {$summary['watchers_count']} watcher(s); you ".($summary['watching'] ? 'are' : 'are not').' one.', $summary);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->string()->description('Issue key, e.g. WEB-12.')->required(),
            'user' => $schema->string()->description('Member email; omit for yourself.'),
        ];
    }
}
