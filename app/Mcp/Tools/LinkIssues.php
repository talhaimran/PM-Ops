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

#[Name('link_issues')]
#[Description('Link two issues, e.g. {"from":"WEB-12","type":"blocks","to":"WEB-15"}. Reverse names (is blocked by, follows…) are accepted; links may cross projects in the workspace; blocks and precedes can\'t loop. Needs Write level.')]
class LinkIssues extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, IssueService $issues, LinkService $links): Response
    {
        $request->validate(['from' => 'required|string|max:30']);
        $from = $issues->resolve($request->user(), $request->get('from'));
        Gate::authorize('update', $from);
        $data = $request->validate((new LinkRequest)->rules());
        $link = $links->link($from, $data['type'], $issues->resolve($request->user(), $data['to']), $request->user());
        $stored = ['from' => $link->source->key, 'type' => $link->type->value, 'to' => $link->target->key];

        return $this->result("Linked: {$stored['from']} {$stored['type']} {$stored['to']}.", $stored);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Issue key, e.g. WEB-12.')->required(),
            'type' => $schema->string()->enum(LinkType::names())->description('How from relates to to: "WEB-12 blocks WEB-15".')->required(),
            'to' => $schema->string()->description('The other issue key, e.g. WEB-15.')->required(),
        ];
    }
}
