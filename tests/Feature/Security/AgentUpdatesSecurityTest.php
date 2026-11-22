<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\AgentService;
use App\Services\ApprovalService;

// Step A, PR 4 review: an agent's approval outcomes are its own. Nothing leaks across agents, owners, workspaces or cursors.

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->web = makeProject($this->owner, 'WEB');
    foreach ([1, 2] as $n) {
        makeIssue($this->web, $this->owner, ['title' => "Issue {$n}", 'status' => 'In Review']);
    }
    [$this->agent, $this->token] = makeAgent($this->owner, AgentLevel::Write, name: 'mine');
    $this->id = asToken($this->token)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertStatus(202)->json('approval_request.id');
    app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($this->id), $this->owner, 'secret reason');
    $this->travel(2)->seconds();
});

function pollUpdates(string $token, string $query = '')
{
    return asToken($token)->getJson('/api/v1/me/updates'.$query);
}

test('an agent of a different owner in the same workspace and project sees none of it', function () {
    $other = addMember($this->web, User::factory()->create(), ProjectRole::Admin);
    [, $token] = makeAgent($other, AgentLevel::Delete);

    pollUpdates($token)->assertOk()->assertJsonPath('data', []);
    pollUpdates($token, '?cursor='.rtrim(strtr(base64_encode('0.0'), '+/', '-_'), '='))->assertOk()->assertJsonPath('data', []);
});

test('the same owner\'s agent in another workspace sees none of it', function () {
    $beta = makeWorkspace($this->owner, 'beta');
    [, $betaToken] = makeAgent($this->owner, AgentLevel::Delete);
    useWorkspace($this->ws, $this->owner);

    pollUpdates($betaToken)->assertOk()->assertJsonPath('data', []);
    pollUpdates($this->token)->assertOk()->assertJsonCount(1, 'data');
});

test('a hand-made cursor only rewinds the caller\'s own history', function () {
    $other = addMember($this->web, User::factory()->create(), ProjectRole::Admin);
    [$otherAgent, $otherToken] = makeAgent($other, AgentLevel::Write);
    $theirs = asToken($otherToken)->postJson('/api/v1/issues/WEB-2/move', ['status' => 'Done'])->assertStatus(202)->json('approval_request.id');
    app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($theirs), $other, null);
    $this->travel(2)->seconds();
    $zero = rtrim(strtr(base64_encode('0.0'), '+/', '-_'), '=');

    expect(pollUpdates($this->token, "?cursor={$zero}")->json('data.*.approval_request_id'))->toBe([$this->id])
        ->and(pollUpdates($otherToken, "?cursor={$zero}")->json('data.*.approval_request_id'))->toBe([$theirs]);
});

test('an owner removed from the project hides those outcomes', function () {
    $member = addMember($this->web, User::factory()->create(), ProjectRole::Member);
    asWorkspaceMember($member);
    [$agent, $token] = makeAgent($member, AgentLevel::Write);
    $id = asToken($token)->postJson('/api/v1/issues/WEB-2/move', ['status' => 'Done'])->assertStatus(202)->json('approval_request.id');
    app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($id), $this->owner, null);
    $this->travel(2)->seconds();
    expect(pollUpdates($token)->json('data'))->toHaveCount(1);

    $this->web->members()->detach($member);

    pollUpdates($token)->assertOk()->assertJsonPath('data', []);
});

test('a paused agent gets 403 agent_paused and a revoked one 401, on REST and MCP', function () {
    $mcp = fn ($token) => asToken($token, ['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_updates', 'arguments' => (object) []]]);

    app(AgentService::class)->pause($this->agent, $this->owner);
    pollUpdates($this->token)->assertForbidden()->assertJsonPath('error', 'agent_paused');
    expect(json_encode($mcp($this->token)->json()))->toContain('paused')->not->toContain('approval:');

    app(AgentService::class)->revoke($this->agent, $this->owner);
    pollUpdates($this->token)->assertUnauthorized();
    $mcp($this->token)->assertUnauthorized();
});

test('a person gets an empty list', function () {
    apiAsPerson($this->owner);

    $this->getJson('/api/v1/me/updates')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.next_cursor', null);
});

test('each update has exactly the documented keys and no other ids or emails', function () {
    $update = pollUpdates($this->token)->assertOk()->json('data.0');

    expect(array_keys($update))->toEqualCanonicalizing(['id', 'kind', 'approval_request_id', 'issue', 'to', 'decided_by', 'note', 'at'])
        ->and(json_encode($update))->not->toContain($this->owner->email);
});
