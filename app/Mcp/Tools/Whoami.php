<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\AgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('whoami')]
#[Description('Who you are acting as: your agent (name, level, status), its owner, the workspace, the projects you can reach, and how many requests are left per minute and per day.')]
#[IsReadOnly]
class Whoami extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, AgentService $agents): Response
    {
        $me = $agents->whoami($request->user());

        return $this->result(
            ($me['agent'] ? "{$me['agent']['name']} ({$me['agent']['level']})" : 'A person')." acting for {$me['owner']['name']}".($me['workspace'] ? " in {$me['workspace']['name']}." : '.'),
            $me,
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
