<?php

use App\Enums\AgentLevel;
use App\Enums\WorkspaceRole;
use App\Mcp\OAuth\AgentAuthCodeRepository;
use App\Models\Agent;
use App\Models\User;
use App\Services\AgentService;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

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
    'another path' => OAUTH_REDIRECT.'/other',
    'another host' => 'https://evil.example/api/mcp/auth_callback',
    'a query added' => OAUTH_REDIRECT.'?x=1',
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
    expect($query['state'])->toBe('abc<>"&')->and($location)->toStartWith(OAUTH_REDIRECT.'?');
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

// Fix round 1: open registration, workspace policies at consent, and the consent-time workspace.

test('registration only takes redirect URIs on allowed hosts and app schemes', function (string $uri, bool $allowed) {
    $response = $this->postJson('/oauth/register', ['client_name' => 'Some app', 'redirect_uris' => [$uri]]);

    $allowed ? $response->assertCreated() : $response->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    expect(Client::count())->toBe($allowed ? 1 : 0);
})->with([
    'claude.ai' => ['https://claude.ai/api/mcp/auth_callback', true],
    'claude.com' => ['https://claude.com/api/mcp/auth_callback', true],
    'localhost' => ['http://localhost:33418/callback', true],
    '127.0.0.1' => ['http://127.0.0.1:6274/oauth/callback', true],
    'cursor' => ['cursor://anysphere.cursor-retrieval/oauth/user-pm/callback', true],
    'vscode' => ['vscode://vscode.github-authentication/did-authenticate', true],
    'another host' => ['https://evil.example/cb', false],
    'a look-alike host' => ['https://claude.ai.evil.example/cb', false],
    'credentials in the URL' => ['https://claude.ai@evil.example/cb', false],
    'an unlisted scheme' => ['myapp://host/cb', false],
]);

test('the allowed hosts and schemes come from PM_MCP_REDIRECT_DOMAINS and PM_MCP_REDIRECT_SCHEMES', function () {
    $config = fn (array $env) => (function () use ($env) {
        foreach ($env as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        try {
            return require base_path('config/mcp.php');
        } finally {
            foreach ($env as $k => $v) {
                putenv($k);
                unset($_ENV[$k], $_SERVER[$k]);
            }
        }
    })();

    expect($config(['PM_MCP_REDIRECT_DOMAINS' => 'example.com, https://app.example.org', 'PM_MCP_REDIRECT_SCHEMES' => 'zed'])['redirect_domains'])
        ->toBe(['https://example.com', 'https://app.example.org'])
        ->and($config(['PM_MCP_REDIRECT_SCHEMES' => 'zed, cursor'])['custom_schemes'])->toBe(['zed', 'cursor'])
        ->and($config([])['redirect_domains'])->toBe(['https://localhost', 'https://127.0.0.1', 'https://claude.ai', 'https://claude.com'])
        ->and($config([])['custom_schemes'])->toBe(['cursor', 'vscode']);
});

test('script-capable schemes are refused even when configured', function (string $scheme) {
    config(['mcp.custom_schemes' => ['cursor', $scheme]]);

    $this->postJson('/oauth/register', ['client_name' => 'x', 'redirect_uris' => ["{$scheme}://example.com/%0Aalert(1)"]])->assertStatus(400);
    expect(Client::count())->toBe(0);
})->with(['javascript', 'JavaScript', 'data', 'file', 'vbscript']);

test('authorization errors only ever redirect to a registered, allowed host', function (array $query) {
    $clientId = oauthClient();
    $this->post('/logout');

    $location = $this->get(oauthAuthorizeUrl($clientId, pkce()[1], $query))->assertRedirect()->headers->get('Location');
    expect(parse_url($location, PHP_URL_HOST))->toBeIn(['claude.ai', 'localhost']);
})->with([
    'prompt=none, signed out' => [['prompt' => 'none']],
    'an unknown scope' => [['scope' => 'bogus']],
]);

test('the consent screen names where the client sends you back and that it registered itself; nothing is preselected', function () {
    $response = $this->actingAs($this->ann)->get(oauthAuthorizeUrl(oauthClient(), pkce()[1]))->assertOk();

    expect($response->viewData('page')['props'])->toMatchArray([
        'redirectTo' => 'https://claude.ai',
        'clientNote' => "Registered itself — PmOps hasn't verified this app.",
    ])->and(file_get_contents(resource_path('js/Pages/OAuth/Authorize.tsx')))->toContain("useForm({ agent: '' })");
});

test('the consent POST needs a choice', function () {
    $clientId = oauthClient();
    $authToken = $this->actingAs($this->ann)->get(oauthAuthorizeUrl($clientId, pkce()[1]))->viewData('page')['props']['authToken'];

    $this->postJson('/oauth/authorize', ['auth_token' => $authToken])->assertUnprocessable()->assertJsonValidationErrors('agent');
    expect(AuthCode::count())->toBe(0);
});

test('workspace policies apply at consent and at approve, as on the workspace\'s own pages', function (Closure $apply, Closure $refused) {
    $clientId = oauthClient();
    $url = oauthAuthorizeUrl($clientId, pkce()[1]);
    $authToken = $this->actingAs($this->ann)->get($url)->viewData('page')['props']['authToken'];
    $apply->call($this);
    $agents = Agent::count();

    $refused($this->actingAs($this->ann)->withSession(['auth.method' => 'password'])->post('/oauth/authorize', ['auth_token' => $authToken, 'agent' => $this->agent->id]));
    $refused($this->get($url));
    expect(AuthCode::count())->toBe(0)->and(Agent::count())->toBe($agents);
})->with([
    'sign-in methods: a password session in a Google-only workspace' => [
        fn () => $this->ws->forceFill(['settings' => [...$this->ws->settings ?? [], 'sign_in_methods' => ['google']]])->save(),
        fn ($response) => $response->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('Workspaces/NoAccess')
            ->where('message', 'Alpha allows sign-in with Google only. Sign out, then sign in that way.')),
    ],
    'require_2fa without two-factor' => [
        fn () => $this->ws->forceFill(['settings' => [...$this->ws->settings ?? [], 'require_2fa' => true]])->save(),
        fn ($response) => $response->assertRedirect(route('profile.edit')),
    ],
    'agents turned off' => [
        fn () => $this->ws->forceFill(['settings' => [...$this->ws->settings ?? [], 'agents_enabled' => false]])->save(),
        fn ($response) => $response->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('Workspaces/NoAccess')
            ->where('message', 'Agents are turned off in Alpha. An Owner or Admin can turn them on in Settings → Security.')),
    ],
    'scheduled for deletion' => [
        fn () => $this->ws->forceFill(['deletion_scheduled_at' => now()->addDays(7)])->save(),
        fn ($response) => $response->status() === 403
            ? $response->assertInertia(fn (Assert $page) => $page->component('Workspaces/NoAccess')->where('message', fn ($m) => str_contains($m, 'scheduled for deletion')))
            // Outside the workspace the screen has no workspace to offer agents from.
            : $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace', null)->where('agents', [])),
    ],
]);

test('approving uses the workspace the consent screen showed; switching workspace in between is refused', function () {
    $bravo = makeWorkspace($this->ann, 'bravo');
    [$bravoAgent] = makeAgent($this->ann, AgentLevel::Write, [], 'Bravo agent');
    useWorkspace($this->ws, $this->ann);
    $clientId = oauthClient();
    $url = oauthAuthorizeUrl($clientId, pkce()[1]);
    $authToken = $this->actingAs($this->ann)->get($url)->viewData('page')['props']['authToken'];

    // Another tab switches to Bravo.
    $this->ann->forceFill(['current_workspace_id' => $bravo->id])->save();
    $this->from($url)->post('/oauth/authorize', ['auth_token' => $authToken, 'agent' => $bravoAgent->id])
        ->assertRedirect($url)->assertSessionHasErrors(['agent' => 'You switched to Bravo after this screen opened. Choose an agent again.']);
    expect(AuthCode::count())->toBe(0);
});

test('a failure while issuing the code leaves no new agent behind', function () {
    $clientId = oauthClient();
    $authToken = $this->actingAs($this->ann)->get(oauthAuthorizeUrl($clientId, pkce()[1]))->viewData('page')['props']['authToken'];
    app()->forgetInstance(AuthorizationServer::class);
    app()->bind(AuthCodeRepository::class, fn () => new class extends AgentAuthCodeRepository
    {
        public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
        {
            throw new RuntimeException('database went away');
        }
    });

    $this->withoutExceptionHandling();
    expect(fn () => $this->post('/oauth/authorize', ['auth_token' => $authToken, 'agent' => 'new']))->toThrow(RuntimeException::class);
    expect(Agent::where('name', "Ann's Claude Desktop agent")->exists())->toBeFalse();
});

test('registration and the consent screen are rate limited', function () {
    foreach (range(1, 60) as $i) {
        $this->postJson('/oauth/register', ['client_name' => "c{$i}", 'redirect_uris' => [OAUTH_REDIRECT]])->assertCreated();
    }
    $this->postJson('/oauth/register', ['client_name' => 'one too many', 'redirect_uris' => [OAUTH_REDIRECT]])->assertTooManyRequests();

    $this->actingAs($this->ann);
    foreach (range(1, 30) as $i) {
        $this->post('/oauth/authorize', ['auth_token' => 'x', 'agent' => 'new']);
    }
    $this->post('/oauth/authorize', ['auth_token' => 'x', 'agent' => 'new'])->assertTooManyRequests();
});
