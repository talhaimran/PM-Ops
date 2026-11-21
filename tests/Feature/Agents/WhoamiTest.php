<?php

use App\Enums\AgentLevel;
use App\Enums\Entitlement;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\Whoami;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Facades\Auth;

// A3: who am I, which limits apply, how many calls are left. Numbers come from the limiter the middleware uses.

beforeEach(function () {
    config(['pm.api_rate_limit' => 10]);
    $this->owner = User::factory()->create(['name' => 'Talha Imran', 'email' => 'talha@example.com']);
    $this->ws = makeWorkspace($this->owner, 'acme');
    makeProject($this->owner, 'WEB');
    makeProject($this->owner, 'API');
});

test('GET /me tells an agent who it is, where, and what is left of its limits', function () {
    [$agent, $token] = makeAgent($this->owner, AgentLevel::Write, ['WEB'], 'Claude Code');

    $response = asToken($token)->getJson('/api/v1/me')->assertOk();

    expect($response->json('data'))->toEqual([
        'agent' => ['id' => $agent->id, 'name' => 'Claude Code', 'level' => 'write', 'status' => 'active'],
        'owner' => ['name' => 'Talha Imran', 'email' => 'talha@example.com'],
        'workspace' => ['slug' => 'acme', 'name' => 'Acme', 'owner_role' => 'owner'],
        'projects' => ['WEB'],
        'rate_limit' => ['per_minute' => ['limit' => 10, 'remaining' => 9], 'per_day' => ['limit' => null, 'remaining' => null]],
    ]);
    // The same counter the throttle middleware keeps: this call is already counted.
    expect((int) $response->headers->get('X-RateLimit-Remaining'))->toBe(9);
    asToken($token)->getJson('/api/v1/me')->assertJsonPath('data.rate_limit.per_minute.remaining', 8);
});

test('an agent without projects reaches all of its owner\'s, and the daily limit shows when there is one', function () {
    app(Entitlements::class)->set($this->ws, Entitlement::ApiPerDay, '50', 'override');
    [, $token] = makeAgent($this->owner, AgentLevel::Read);

    asToken($token)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.projects', 'all')
        ->assertJsonPath('data.rate_limit.per_day', ['limit' => 50, 'remaining' => 49]);
});

test('limits are per agent: another agent of the same owner has its own count', function () {
    [, $a] = makeAgent($this->owner);
    [, $b] = makeAgent($this->owner);
    asToken($a)->getJson('/api/v1/me');
    asToken($a)->getJson('/api/v1/me');

    asToken($b)->getJson('/api/v1/me')->assertJsonPath('data.rate_limit.per_minute.remaining', 9);
});

test('a person (no agent token) gets agent null', function () {
    apiAsPerson($this->owner);

    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.agent', null)
        ->assertJsonPath('data.owner.email', 'talha@example.com')
        ->assertJsonPath('data.workspace.slug', 'acme');
});

test('the whoami tool returns the same facts over MCP', function () {
    [$agent, $token] = makeAgent($this->owner, AgentLevel::Delete, name: 'Cursor');
    Auth::setUser($this->owner->fresh()->withAccessToken($agent->token()->sole()));

    PmServer::tool(Whoami::class)->assertOk()
        ->assertSee(['Cursor', '"level":"delete"', '"owner":{"name":"Talha Imran","email":"talha@example.com"}', '"slug":"acme"', '"owner_role":"owner"', '"projects":"all"', '"per_minute":{"limit":10,"remaining":10}']);
});

test('MCP over HTTP counts its calls against the same limit whoami reports', function () {
    [, $token] = makeAgent($this->owner);
    $call = fn () => asToken($token, ['Accept' => 'application/json, text/event-stream'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => (object) []]]);

    $call()->assertOk();
    $text = $call()->assertOk()->json('result.content.0.text');

    expect($text)->toContain('"per_minute":{"limit":10,"remaining":8}');
});

test('429 answers carry Retry-After on REST and on MCP over HTTP', function () {
    app(Entitlements::class)->set($this->ws, Entitlement::ApiPerMinute, '1', 'override');
    [, $rest] = makeAgent($this->owner);
    [, $mcp] = makeAgent($this->owner);

    asToken($rest)->getJson('/api/v1/me')->assertOk();
    asToken($rest)->getJson('/api/v1/me')->assertStatus(429)->assertHeader('Retry-After');

    $rpc = fn () => asToken($mcp, ['Accept' => 'application/json, text/event-stream'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $rpc()->assertOk();
    $rpc()->assertStatus(429)->assertHeader('Retry-After');
});
