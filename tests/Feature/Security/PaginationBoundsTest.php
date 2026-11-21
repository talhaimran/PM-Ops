<?php

use App\Enums\AgentLevel;
use App\Models\User;

// Forged or absurd page numbers and cursors are a 422, never a 500 (huge ints overflow the offset).

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->project = makeProject($this->owner, 'WEB');
    makeIssue($this->project, $this->owner, ['title' => 'One']);
    [, $this->token] = makeAgent($this->owner, AgentLevel::Write);
});

function b64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function mcpRaw(string $token, string $tool, array $arguments)
{
    return asToken($token, ['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);
}

test('page 10001 is a 422 on REST lists and MCP; page 10000 is an empty 200', function () {
    foreach (['/api/v1/projects/WEB/issues', '/api/v1/projects', '/api/v1/projects/WEB/sprints', '/api/v1/issues'] as $url) {
        asToken($this->token)->getJson("{$url}?page=10001")->assertUnprocessable();
        asToken($this->token)->getJson("{$url}?page=99999999999999999999")->assertUnprocessable();
        asToken($this->token)->getJson("{$url}?page=10000")->assertOk()->assertJsonPath('data', []);
    }
    foreach ([['search_issues', []], ['list_projects', []], ['list_sprints', ['project' => 'WEB']], ['get_issue_activity', ['issue' => 'WEB-1']]] as [$tool, $args]) {
        mcpRaw($this->token, $tool, [...$args, 'page' => 10001])->assertOk()->assertJsonPath('result.isError', true);
        expect(mcpRaw($this->token, $tool, [...$args, 'page' => 10000])->assertOk()->json('result.isError'))->toBeFalse();
    }
});

test('forged list cursors are a 422 on REST and MCP, never a 500', function (string $raw) {
    $cursor = b64($raw);

    foreach (['/api/v1/projects/WEB/issues', '/api/v1/projects', '/api/v1/projects/WEB/sprints', '/api/v1/issues/WEB-1/activity'] as $url) {
        asToken($this->token)->getJson("{$url}?cursor={$cursor}")->assertUnprocessable()->assertJsonValidationErrors('cursor');
    }
    mcpRaw($this->token, 'search_issues', ['cursor' => $cursor])->assertOk()->assertJsonPath('result.isError', true);
    mcpRaw($this->token, 'list_projects', ['cursor' => $cursor])->assertOk()->assertJsonPath('result.isError', true);
})->with(['99999999999999999999.10', '0.10', '1.1000', str_repeat('9', 30).'.10', '2.'.str_repeat('9', 30), '10001.10']);

test('forged update cursors (long or future timestamp, long id) are a 422 on REST and MCP', function (string $raw) {
    $cursor = b64($raw);

    asToken($this->token)->getJson("/api/v1/me/updates?cursor={$cursor}")->assertUnprocessable()->assertJsonValidationErrors('cursor');
    mcpRaw($this->token, 'get_updates', ['cursor' => $cursor])->assertOk()->assertJsonPath('result.isError', true);
})->with(fn () => [str_repeat('9', 30).'.1', '4102444800.1', '0.'.str_repeat('9', 30)]);

test('an issued update cursor still works, as does the zero cursor', function () {
    asToken($this->token)->getJson('/api/v1/me/updates?cursor='.b64('0.0'))->assertOk();
    asToken($this->token)->getJson('/api/v1/me/updates?cursor='.b64(now()->timestamp.'.5'))->assertOk();
});
