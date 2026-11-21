<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\ApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_updates')]
#[Description('What happened to your approval requests: approved, rejected, expired or stale, oldest first, with who decided and why. Pass the returned next_cursor next time to get only newer updates.')]
#[IsReadOnly]
class GetUpdates extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, ApprovalService $approvals): Response
    {
        $data = $request->validate(ApprovalService::UPDATE_RULES);
        $result = $approvals->updatesFor($request->user(), $request->user()->agent(), $data['cursor'] ?? null, $data['per_page'] ?? 25);
        $count = count($result['updates']);

        return $this->result($count ? "{$count} ".Str::plural('update', $count).'. Pass next_cursor to get only newer ones.' : 'No updates.', $result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'cursor' => $schema->string()->description('next_cursor from the previous call; leave out to start from the oldest.'),
            'per_page' => $schema->integer()->min(1)->max(50)->description('Default 25.'),
        ];
    }
}
