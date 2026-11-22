<?php

use App\Enums\AgentLevel;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Services\AgentService;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Token;

// A-D3 probes: OAuth for MCP clients never lets a client act as an agent the signed-in person didn't pick.

beforeEach(function () {
    $this->ann = User::factory()->create(['name' => 'Ann Lee', 'email' => 'ann@example.com']);
    $this->ws = makeWorkspace($this->ann, 'alpha');
    makeProject($this->ann, 'WEB', 'Alpha web');
    [$this->agent] = makeAgent($this->ann, AgentLevel::Write, [], 'Ann agent');
});

test('the redirect URI must exactly match the registered one', function (string $redirect) {
    $clientId = oauthClient();

    $this->actingAs($this->ann)->get(oauthAuthorizeUrl($clientId, pkce()[1], ['redirect_uri' => $redirect]))
        ->assertStatus(401)->assertJsonPath('error', 'invalid_client');
})->with([
    'another path' => 'https://client.example/callback/other',
    'another host' => 'https://evil.example/callback',
    'a query added' => 'https://client.example/callback?x=1',
]);

test('PKCE with S256 is required: no challenge or a plain one is refused before consent', function (array $query) {
    $clientId = oauthClient();

    $this->actingAs($this->ann)->get(oauthAuthorizeUrl($clientId, 'challenge-'.str_repeat('a', 43), $query))
        ->assertStatus(400)->assertJsonPath('error', 'invalid_request');
})->with([
    'omitted' => [['code_challenge' => null, 'code_challenge_method' => null]],
    'plain' => [['code_challenge_method' => 'plain']],
    'method only' => [['code_challenge' => null]],
]);

test('the code needs the matching verifier', function () {
    $clientId = oauthClient();
    [, $challenge] = pkce();
    $code = oauthCode($this->ann, $clientId, $this->agent->id, $challenge);

    oauthExchange($clientId, $code, pkce()[0])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

test('state comes back to the client unchanged', function () {
    $clientId = oauthClient();
    $authToken = $this->actingAs($this->ann)->get(oauthAuthorizeUrl($clientId, pkce()[1], ['state' => 'abc<>"&']))->viewData('page')['props']['authToken'];

    $location = $this->post('/oauth/authorize', ['auth_token' => $authToken, 'agent' => $this->agent->id])->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('abc<>"&')->and($location)->toStartWith('https://client.example/callback?');
});

test('a code is redeemed once, and only by the client it was issued to', function () {
    $clientId = oauthClient();
    $otherClient = oauthClient('Other client');
    [$verifier, $challenge] = pkce();
    $code = oauthCode($this->ann, $clientId, $this->agent->id, $challenge);

    oauthExchange($otherClient, $code, $verifier)->assertStatus(400);
    oauthExchange($clientId, $code, $verifier)->assertOk();
    oauthExchange($clientId, $code, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

test('the consent POST only takes an active agent of the signed-in person in the current workspace', function (Closure $agent) {
    $clientId = oauthClient();
    $url = oauthAuthorizeUrl($clientId, pkce()[1]);
    $authToken = $this->actingAs($this->ann)->get($url)->viewData('page')['props']['authToken'];
    $agentId = $agent->call($this);
    $this->actingAs($this->ann);

    $this->from($url)->post('/oauth/authorize', ['auth_token' => $authToken, 'agent' => $agentId])
        ->assertRedirect($url)->assertSessionHasErrors('agent');
    expect(AuthCode::count())->toBe(0);
})->with([
    "someone else's agent" => [function () {
        $bob = User::factory()->create();
        joinWorkspace($bob);

        return makeAgent($bob, AgentLevel::Delete, [], 'Bob agent')[0]->id;
    }],
    'my agent in another workspace' => [function () {
        makeWorkspace($this->ann, 'bravo');
        $id = makeAgent($this->ann, AgentLevel::Delete, [], 'Bravo agent')[0]->id;
        useWorkspace($this->ws, $this->ann);

        return $id;
    }],
    'my paused agent' => [function () {
        app(AgentService::class)->pause($this->agent, $this->ann);

        return $this->agent->id;
    }],
    'my revoked agent' => [function () {
        app(AgentService::class)->revoke($this->agent, $this->ann);

        return $this->agent->id;
    }],
    'an agent that does not exist' => [fn () => 999999],
]);

test('a client someone else registered still needs my consent, every time', function () {
    $bob = User::factory()->create();
    $this->actingAs($bob);
    $clientId = oauthClient('Bob client');
    [$verifier, $challenge] = pkce();
    oauthExchange($clientId, oauthCode($this->ann, $clientId, $this->agent->id, $challenge), $verifier)->assertOk();

    // A second authorization (prompt or not) shows the consent screen again instead of handing out a code.
    $this->actingAs($this->ann)->get(oauthAuthorizeUrl($clientId, pkce()[1]))->assertOk()->assertSee('OAuth\/Authorize', false);
    $this->actingAs($this->ann)->get(oauthAuthorizeUrl($clientId, pkce()[1], ['prompt' => 'none']))->assertRedirect()
        ->assertRedirectContains('error=consent_required');
});

test('the consent POST needs the auth token of this session', function () {
    $clientId = oauthClient();
    $this->actingAs($this->ann)->get(oauthAuthorizeUrl($clientId, pkce()[1]));

    $this->post('/oauth/authorize', ['auth_token' => 'forged', 'agent' => $this->agent->id])->assertForbidden();
    expect(AuthCode::count())->toBe(0);
});

test('an OAuth token for an agent in workspace A never reaches workspace B', function () {
    $bob = User::factory()->create();
    $bravo = makeWorkspace($bob, 'bravo');
    makeProject($bob, 'OPS', 'B-SECRET ops');
    $bravo->addMember($this->ann, WorkspaceRole::Admin);
    useWorkspace($this->ws, $this->ann);
    $tokens = oauthConnect($this->ann, $this->agent->id);

    // Even with B as her current workspace in the web app, the token stays in A.
    $this->ann->forceFill(['current_workspace_id' => $bravo->id])->save();
    expect(json_encode(mcpJson($tokens['access_token'], 'list_projects')))->toContain('WEB')->not->toContain('OPS')->not->toContain('B-SECRET')
        ->and(mcpJson($tokens['access_token'], 'whoami')['workspace']['slug'])->toBe('alpha');
});

test('an OAuth token without an agent, or whose owner left the workspace, is refused', function () {
    $tokens = oauthConnect($this->ann, $this->agent->id);
    $mcp = fn () => asToken($tokens['access_token'], ['Accept' => 'application/json, text/event-stream'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

    Token::query()->update(['agent_id' => null]);
    $mcp()->assertUnauthorized();

    Token::query()->update(['agent_id' => $this->agent->id]);
    $mcp()->assertOk();
    $bob = User::factory()->create();
    $this->ws->addMember($bob, WorkspaceRole::Owner);
    $this->ws->members()->detach($this->ann);
    $mcp()->assertUnauthorized();
    expect($this->agent->fresh()->status->value)->toBe('revoked');
});

test('a client name with HTML is shown as text on the consent screen and in the agent it creates', function () {
    $evil = '<script>alert(1)</script><img src=x onerror=alert(2)>';
    $clientId = oauthClient($evil);

    $response = $this->actingAs($this->ann)->get(oauthAuthorizeUrl($clientId, pkce()[1]))->assertOk();
    expect($response->getContent())->not->toContain('<script>alert(1)</script>')->not->toContain('<img src=x')
        ->and($response->viewData('page')['props']['client'])->toBe($evil);
});

test('Passport\'s own token, client and personal-token endpoints are not exposed', function (string $method, string $uri) {
    $this->actingAs($this->ann)->json($method, $uri)->assertNotFound();
})->with([
    ['GET', '/oauth/clients'], ['POST', '/oauth/clients'], ['GET', '/oauth/tokens'], ['GET', '/oauth/scopes'],
    ['GET', '/oauth/personal-access-tokens'], ['POST', '/oauth/personal-access-tokens'], ['POST', '/oauth/token/refresh'],
]);

test('the consent screen cannot be framed (clickjacking)', function () {
    $this->actingAs($this->ann)->get(oauthAuthorizeUrl(oauthClient(), pkce()[1]))->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
});
