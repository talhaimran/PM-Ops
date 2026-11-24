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
#[Description('What happened since you last asked, oldest first, in one stream: how your approval requests ended (approved, rejected, expired or stale, with who decided and why) and the notifications of the person you act for (changes to issues they watch and mentions, not the daily summary). Pass the returned next_cursor next time to get only newer updates, or `since` (ISO 8601 time) on the first call. Reading marks nothing read.')]
#[IsReadOnly]
class GetUpdates extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, ApprovalService $approvals): Response
    {
        $data = $request->validate(ApprovalService::UPDATE_RULES);
        $result = $approvals->updatesFor($request->user(), $request->user()->agent(), $data['cursor'] ?? null, $data['per_page'] ?? 25, $data['since'] ?? null);
        $count = count($result['updates']);

        return $this->result($count ? "{$count} ".Str::plural('update', $count).'. Pass next_cursor to get only newer ones.' : 'No updates.', $result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'cursor' => $schema->string()->description('next_cursor from the previous call; leave out to start from the oldest.'),
            'since' => $schema->string()->description('ISO 8601 time; only when there is no cursor: updates from then on.'),
            'per_page' => $schema->integer()->min(1)->max(50)->description('Default 25.'),
        ];
    }
}
