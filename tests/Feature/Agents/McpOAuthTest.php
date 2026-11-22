<?php

use App\Enums\AgentLevel;
use App\Enums\Entitlement;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AgentService;
use App\Services\Entitlements;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Laravel\Sanctum\PersonalAccessToken;

// A1/A3 (decision A-D3): MCP clients connect with OAuth; the consent screen picks which of my agents the client acts as.

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Talha Imran', 'email' => 'talha@example.com']);
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->web = makeProject($this->owner, 'WEB');
    [$this->agent] = makeAgent($this->owner, AgentLevel::Write, [], 'Claude Code');
});

function mcpCall(string $token, string $method, array $params = [])
{
    return asToken($token, ['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, ...($params ? ['params' => $params] : [])]);
}

test('discovery metadata points at this app, with registration and S256 PKCE', function () {
    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
        ->assertJsonPath('resource', url('/mcp'))
        ->assertJsonPath('authorization_servers', [url('/')]);
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
        ->assertJsonPath('token_endpoint', url('/oauth/token'))
        ->assertJsonPath('registration_endpoint', url('/oauth/register'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256']);

    // An MCP client finds the metadata from the 401 on /mcp.
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
});

test('the consent screen lists only my active agents in the current workspace, and offers a new one', function () {
    [$paused] = makeAgent($this->owner, AgentLevel::Read, [], 'Paused one');
    app(AgentService::class)->pause($paused, $this->owner);
    [$revoked] = makeAgent($this->owner, AgentLevel::Read, [], 'Revoked one');
    app(AgentService::class)->revoke($revoked, $this->owner);
    $alex = User::factory()->create();
    joinWorkspace($alex);
    makeAgent($alex, AgentLevel::Write, [], 'Alex agent');
    $other = makeWorkspace($this->owner, 'other');
    makeAgent($this->owner, AgentLevel::Write, [], 'Other workspace agent');
    useWorkspace($this->ws, $this->owner);

    $clientId = oauthClient();
    $this->actingAs($this->owner)->get(oauthAuthorizeUrl($clientId, pkce()[1]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('OAuth/Authorize')
            ->where('client', 'Claude Desktop')
            ->where('workspace', 'Acme')
            ->where('agents', [['id' => $this->agent->id, 'name' => 'Claude Code', 'level' => 'write']])
            ->where('newAgent', "Talha's Claude Desktop agent")
            ->has('authToken'));
    expect($other->exists)->toBeTrue();
});

test('approving issues a token that acts on /mcp as the chosen agent, recorded like an agent token', function () {
    $tokens = oauthConnect($this->owner, $this->agent->id);

    expect($tokens['token_type'])->toBe('Bearer')->and($tokens['refresh_token'])->not->toBeEmpty();
    mcpCall($tokens['access_token'], 'tools/list')->assertOk()->assertJsonPath('result.tools.0.name', 'list_projects');
    expect(mcpJson($tokens['access_token'], 'whoami'))->toMatchArray([
        'agent' => ['id' => $this->agent->id, 'name' => 'Claude Code', 'level' => 'write', 'status' => 'active'],
        'workspace' => ['slug' => 'acme', 'name' => 'Acme', 'owner_role' => 'owner'],
    ]);

    mcpJson($tokens['access_token'], 'create_issue', ['project' => 'WEB', 'title' => 'From OAuth']);
    expect(Activity::where('action', 'created')->latest('id')->first()->only('via', 'agent_id', 'token_name'))
        ->toBe(['via' => 'mcp', 'agent_id' => $this->agent->id, 'token_name' => 'Claude Code'])
        ->and($this->agent->fresh()->last_used_at)->not->toBeNull();
});

test('the OAuth token is refused on the REST API and on web pages', function () {
    $tokens = oauthConnect($this->owner, $this->agent->id);

    asToken($tokens['access_token'])->getJson('/api/v1/projects')->assertUnauthorized();
    asToken($tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    asToken($tokens['access_token'])->get(w('/dashboard'))->assertRedirect('/login');
});

test('Sanctum agent tokens keep working on /mcp', function () {
    [, $plain] = makeAgent($this->owner, AgentLevel::Read, [], 'Script');
    expect(mcpJson($plain, 'whoami')['agent']['name'])->toBe('Script');
});

test('pausing the agent makes its OAuth token 403 agent_paused; resuming lets it back in', function () {
    $tokens = oauthConnect($this->owner, $this->agent->id);
    app(AgentService::class)->pause($this->agent, $this->owner);

    mcpCall($tokens['access_token'], 'tools/list')->assertForbidden()->assertJsonPath('error', 'agent_paused');

    app(AgentService::class)->resume($this->agent, $this->owner);
    mcpCall($tokens['access_token'], 'tools/list')->assertOk();
});

test('revoking the agent revokes its OAuth access and refresh tokens: 401, and no refresh', function () {
    $tokens = oauthConnect($this->owner, $this->agent->id);
    app(AgentService::class)->revoke($this->agent, $this->owner);

    mcpCall($tokens['access_token'], 'tools/list')->assertUnauthorized();
    expect(Token::where('agent_id', $this->agent->id)->where('revoked', false)->exists())->toBeFalse()
        ->and(RefreshToken::where('revoked', false)->exists())->toBeFalse();
    $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token']])
        ->assertStatus(400);
});

test('a refreshed token still acts as the same agent', function () {
    $tokens = oauthConnect($this->owner, $this->agent->id);

    $fresh = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token']])
        ->assertOk()->json('access_token');

    expect(mcpJson($fresh, 'whoami')['agent']['id'])->toBe($this->agent->id);
    mcpCall($tokens['access_token'], 'tools/list')->assertUnauthorized();
});

test('creating an agent on the consent screen makes a Write-level agent named for the client, audited', function () {
    $tokens = oauthConnect($this->owner, 'new');

    $agent = Agent::where('name', "Talha's Claude Desktop agent")->sole();
    expect($agent->permission_level)->toBe(AgentLevel::Write)
        ->and($agent->owner_user_id)->toBe($this->owner->id)
        ->and($agent->workspace_id)->toBe($this->ws->id)
        ->and(AuditLog::where('action', 'agent.created')->where('target_id', $agent->id)->exists())->toBeTrue()
        ->and(mcpJson($tokens['access_token'], 'whoami')['agent']['id'])->toBe($agent->id);

    // Its only credential is the OAuth token: no unused Sanctum token, and no token expiry on the Agents page.
    expect(PersonalAccessToken::where('agent_id', $agent->id)->exists())->toBeFalse()
        ->and(collect(app(AgentService::class)->listFor($this->owner, $this->ws))->firstWhere('id', $agent->id))
        ->toMatchArray(['has_token' => false, 'expires_at' => null, 'connections' => ['Claude Desktop']]);
});

test('creating an agent on the consent screen counts toward agents_per_user', function () {
    app(Entitlements::class)->set($this->ws, Entitlement::AgentsPerUser, '1', 'override');
    $clientId = oauthClient();
    $url = oauthAuthorizeUrl($clientId, pkce()[1]);
    $authToken = $this->actingAs($this->owner)->get($url)->viewData('page')['props']['authToken'];

    $this->from($url)->post('/oauth/authorize', ['auth_token' => $authToken, 'agent' => 'new'])
        ->assertRedirect($url)->assertSessionHasErrors(['plan_limit' => 'Your plan allows 1 agent. Upgrade to add more.']);
    expect(Agent::count())->toBe(1);
});

test('denying sends the client back with access_denied and the state', function () {
    $clientId = oauthClient();
    $authToken = $this->actingAs($this->owner)->get(oauthAuthorizeUrl($clientId, pkce()[1]))->viewData('page')['props']['authToken'];

    $location = $this->delete('/oauth/authorize', ['auth_token' => $authToken])->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith(OAUTH_REDIRECT.'?')->toContain('error=access_denied')->toContain('state=state-123');
});

test('the Agents page shows which clients an agent is connected through', function () {
    oauthConnect($this->owner, $this->agent->id);

    $row = collect(app(AgentService::class)->listFor($this->owner, $this->ws))->firstWhere('id', $this->agent->id);
    expect($row['connections'])->toBe(['Claude Desktop']);

    app(AgentService::class)->revoke($this->agent, $this->owner);
    [$other] = makeAgent($this->owner, AgentLevel::Read, [], 'Not connected');
    expect(collect(app(AgentService::class)->listFor($this->owner, $this->ws))->firstWhere('id', $other->id)['connections'])->toBe([]);
});
