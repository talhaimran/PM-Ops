<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Agent;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\AgentService;
use App\Services\CurrentWorkspace;
use App\Services\InvitationService;
use App\Services\IssueService;
use App\Services\ProjectService;
use App\Services\TwoFactorService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Tests that spawn real processes need committed rows, so they truncate instead of using transactions.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** A workspace owned by $owner, made current for service calls and for the owner's web requests. */
function makeWorkspace(User $owner, string $slug): Workspace
{
    // Only Cloud allows a second workspace.
    if (Workspace::exists()) {
        config(['pm.edition' => 'cloud']);
    }

    $workspace = app(WorkspaceService::class)->create($owner, ucfirst($slug), $slug);
    // Tests run unlimited unless they set a limit; D6's Cloud defaults are tested in EntitlementEnforcementTest.
    $workspace->entitlements()->delete();

    return useWorkspace($workspace, $owner);
}

function useWorkspace(Workspace $workspace, ?User $user = null): Workspace
{
    app(CurrentWorkspace::class)->set($workspace);
    $user?->forceFill(['current_workspace_id' => $workspace->id])->save();

    return $workspace;
}

/**
 * Puts $user in the test's workspace, like a migrated install where everyone shares one: the current workspace
 * if one is set ($user joins as Member), else a new one $user owns.
 */
function joinWorkspace(User $user): Workspace
{
    $current = app(CurrentWorkspace::class);
    if (! $current->id()) {
        return makeWorkspace($user, 'ws-'.$user->id);
    }

    $workspace = $current->get();
    if (! $user->workspaceRole($workspace)) {
        $workspace->addMember($user, WorkspaceRole::Member);
    }
    if (! $user->current_workspace_id) {
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    }

    return $workspace;
}

/** Makes $user a plain Member of the current workspace: they see only their own projects (Owners and Admins see all, spec 7.0b). */
function asWorkspaceMember(User $user): User
{
    app(CurrentWorkspace::class)->get()->members()->updateExistingPivot($user->id, ['role' => WorkspaceRole::Member->value]);

    return $user;
}

/** Creates the project in the current workspace (making one on first use), with $admin as its Admin. */
function makeProject(User $admin, string $key = 'WEB', string $name = 'Website'): Project
{
    joinWorkspace($admin);

    return app(ProjectService::class)->create($admin, ['key' => $key, 'name' => $name]);
}

function addMember(Project $project, User $user, ProjectRole $role): User
{
    if (! $user->workspaceRole($project->workspace)) {
        $project->workspace->addMember($user, WorkspaceRole::Member);
    }
    if (! $user->current_workspace_id) {
        $user->forceFill(['current_workspace_id' => $project->workspace_id])->save();
    }
    $project->members()->attach($user, ['role' => $role->value]);

    return $user;
}

/** A pending invitation to $project, as a project admin sends it. */
function makeInvitation(Project $project, User $inviter, string $email, string $role): WorkspaceInvitation
{
    return app(InvitationService::class)->invite($project->workspace, $inviter, $email, WorkspaceRole::Member, $project, ProjectRole::from($role));
}

/** E10: a web path inside the test's workspace, e.g. w('/projects/WEB') → '/ws-1/projects/WEB'. */
function w(string $path): string
{
    $current = app(CurrentWorkspace::class);

    return '/'.($current->id() ? $current->get() : Workspace::orderBy('id')->firstOrFail())->slug.$path;
}

/** A fixture issue, made by $reporter as a person even after apiAs($reporter) (agents can't create in Done, A-L6). */
function makeIssue(Project $project, User $reporter, array $data = []): Issue
{
    return app(IssueService::class)->create($project, (clone $reporter)->withAccessToken(null), ['title' => 'Issue', ...$data]);
}

/**
 * An agent of $owner in the current workspace (made through AgentService, so audited like a real one).
 *
 * @param  list<string>  $projects  project keys; empty = all of the owner's
 * @return array{0: Agent, 1: string} the agent and its plain token
 */
function makeAgent(User $owner, AgentLevel $level = AgentLevel::Delete, array $projects = [], ?string $name = null): array
{
    return app(AgentService::class)->create($owner, ['name' => $name ?? 'agent '.(Agent::max('id') + 1), 'level' => $level->value, 'projects' => $projects]);
}

/** Authenticate as a new agent of $user (Delete level by default: everything $user's role allows). */
function apiAs(User $user, AgentLevel $level = AgentLevel::Delete, array $projects = []): User
{
    if (! $user->workspaces()->exists()) {
        joinWorkspace($user);
    }
    [$agent] = makeAgent($user, $level, $projects);
    $user->withAccessToken($agent->token()->sole());
    app('auth')->guard('sanctum')->setUser($user);
    app('auth')->shouldUse('sanctum');

    return $user;
}

/**
 * Authenticate as $user with no token, like a first-party session call to the API: what people (not agents) may do
 * on REST and MCP, e.g. project settings (A-D2).
 */
function apiAsPerson(User $user): User
{
    if (! $user->workspaces()->exists()) {
        joinWorkspace($user);
    }
    $user->withAccessToken(null);
    app('auth')->guard('sanctum')->setUser($user);
    app('auth')->shouldUse('sanctum');

    return $user;
}

/** A fresh request with only $token as credentials: withHeader/withToken persist and Sanctum caches the user within a test. */
function asToken(string $token, array $headers = [])
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token)->withHeaders($headers);
}

/** Calls an MCP tool over HTTP as $token's agent; returns the JSON part of its text (after the summary line). */
function mcpJson(string $token, string $tool, array $arguments = []): array
{
    $text = asToken($token, ['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]])
        ->assertOk()->json('result.content.0.text');

    return json_decode(substr($text, strpos($text, "\n\n") + 2), true, flags: JSON_THROW_ON_ERROR) ?? [];
}

/** The current code for $user's secret. Fortify refuses a code twice in its window, so tests clear its cache between uses. */
function otp(User $user): string
{
    return (new Google2FA)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret));
}

/** @return list<string> the recovery codes */
function enableTwoFactor(User $user): array
{
    app(TwoFactorService::class)->enable($user);
    $codes = app(TwoFactorService::class)->confirm($user->fresh(), otp($user));
    Cache::flush();

    return $codes;
}

/** A redirect URI the default PM_MCP_REDIRECT_DOMAINS allows (claude.ai's connector callback). */
const OAUTH_REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

/** Registers an MCP client through laravel/mcp's dynamic client registration, as Claude Desktop does; returns its id. */
function oauthClient(string $name = 'Claude Desktop', string $redirect = OAUTH_REDIRECT): string
{
    return test()->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => [$redirect]])->assertCreated()->json('client_id');
}

/** @return array{0: string, 1: string} a PKCE verifier and its S256 challenge */
function pkce(): array
{
    $verifier = Str::random(64);

    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

/** The authorization request a client sends, with S256 PKCE unless $query overrides it. */
function oauthAuthorizeUrl(string $clientId, string $challenge, array $query = []): string
{
    return '/oauth/authorize?'.http_build_query([
        'client_id' => $clientId, 'redirect_uri' => OAUTH_REDIRECT, 'response_type' => 'code',
        'scope' => 'mcp:use', 'state' => 'state-123', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', ...$query,
    ]);
}

/** $user opens the consent screen for $clientId and approves it as $agent (an agent id or 'new'); returns the code. */
function oauthCode(User $user, string $clientId, int|string $agent, string $challenge): string
{
    $authToken = test()->actingAs($user)->get(oauthAuthorizeUrl($clientId, $challenge))->assertOk()->viewData('page')['props']['authToken'];
    $location = test()->post('/oauth/authorize', ['auth_token' => $authToken, 'agent' => $agent])->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query['code'];
}

/** The token endpoint's answer to exchanging $code. */
function oauthExchange(string $clientId, string $code, string $verifier)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => OAUTH_REDIRECT,
        'code_verifier' => $verifier, 'code' => $code,
    ]);
}

/**
 * The whole flow: register a client, consent as $user choosing $agent, exchange the code.
 *
 * @return array{access_token: string, refresh_token: string, client_id: string}
 */
function oauthConnect(User $user, int|string $agent, string $clientName = 'Claude Desktop'): array
{
    $clientId = oauthClient($clientName);
    [$verifier, $challenge] = pkce();
    $tokens = oauthExchange($clientId, oauthCode($user, $clientId, $agent, $challenge), $verifier)->assertOk()->json();

    return [...$tokens, 'client_id' => $clientId];
}

/** Channel auth runs on the Reverb broadcaster (phpunit.xml sets the null one, which checks nothing); the channels are registered again on it. */
function useReverbForChannelAuth(): void
{
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => '1']);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
}

/** $user's session asks to subscribe to the private $channel (the name without "private-"). */
function channelAuth(User $user, string $channel)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"]);
}
