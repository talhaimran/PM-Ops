<?php

namespace App\Mcp\Tools;

use App\Enums\LinkType;
use App\Http\Requests\Issues\LinkRequest;
use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\IssueService;
use App\Services\LinkService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('unlink_issues')]
#[Description('Remove a link between two issues; the same arguments as link_issues. Needs Write level.')]
class UnlinkIssues extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, IssueService $issues, LinkService $links): Response
    {
        $request->validate(['from' => 'required|string|max:30']);
        $data = $request->validate((new LinkRequest)->rules());
        $from = $issues->resolve($request->user(), $request->get('from'));
        $to = $issues->resolve($request->user(), $data['to']);
        Gate::authorize('unlink', [$from, $to]);
        [$type, $reversed] = LinkType::parse($data['type']);
        $links->unlink($from, $data['type'], $to, $request->user());
        [$source, $target] = $reversed ? [$to, $from] : [$from, $to];

        return $this->result("Unlinked: {$source->key} {$type->value} {$target->key}.", ['from' => $source->key, 'type' => $type->value, 'to' => $target->key]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Issue key, e.g. WEB-12.')->required(),
            'type' => $schema->string()->enum(LinkType::names())->description('The link as from sees it, e.g. blocks or is blocked by.')->required(),
            'to' => $schema->string()->description('The other issue key, e.g. WEB-15.')->required(),
        ];
    }
}
