<?php

namespace App\Http\Controllers\Web;

use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolveWorkspace;
use App\Mcp\OAuth\AgentAccessTokenRepository;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentService;
use App\Services\CurrentWorkspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\RetrievesAuthRequestFromSession;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A-D3: OAuth for MCP clients. Passport checks the client, redirect URI and PKCE; the consent screen picks which of
 * my agents the client acts as (or makes a new one), and every authorization shows it: no silent re-approval.
 */
class OAuthController extends AuthorizationController
{
    use RetrievesAuthRequestFromSession;

    /** Session key: the auth token and workspace the consent screen was shown for. */
    private const CONSENT = 'pm.oauth_consent';

    public function authorize(ServerRequestInterface $psrRequest, Request $request, ResponseInterface $psrResponse, AuthorizationViewResponse $viewResponse): Response|AuthorizationViewResponse
    {
        // league also takes "plain", and no PKCE at all from a confidential client.
        if ($request->query('code_challenge_method') !== 'S256' || ! $request->filled('code_challenge')) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'PKCE is required: send code_challenge with code_challenge_method=S256.'], 400);
        }

        return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
    }

    /** The consent screen (Passport's authorization view), in the current workspace, under that workspace's policies. */
    public static function consent(array $params): Response
    {
        [$request, $user, $client] = [$params['request'], $params['user'], $params['client']];
        $current = app(CurrentWorkspace::class);
        $workspace = $current->id() ? $current->get() : null;
        if (! $workspace) {
            // In no workspace: the same page the web app shows, not an empty consent screen.
            $refused = app(WorkspaceController::class)->noAccess($request);

            return $refused instanceof RedirectResponse ? $refused : $refused->toResponse($request)->setStatusCode(403);
        }
        if ($refused = self::refusal($request, $workspace, $user)) {
            return $refused;
        }
        // approve() acts in the workspace shown here, for this auth token only.
        $request->session()->put(self::CONSENT, ['auth_token' => $params['authToken'], 'workspace_id' => $workspace->id]);
        $back = parse_url($request->query('redirect_uri') ?: $client->redirect_uris[0]);

        return Inertia::render('OAuth/Authorize', [
            'client' => $client->name,
            'clientNote' => "Registered itself — PmOps hasn't verified this app.",
            'redirectTo' => $back['scheme'].'://'.$back['host'].(isset($back['port']) ? ':'.$back['port'] : ''),
            'workspace' => $workspace->name,
            'agents' => app(AgentService::class)->oauthChoices($user),
            'newAgent' => AgentService::oauthAgentName($user, $client->name),
            'authToken' => $params['authToken'],
        ])->toResponse($request);
    }

    public function approve(Request $request, ResponseInterface $psrResponse, AgentService $agents, CurrentWorkspace $current): Response
    {
        $request->validate(['agent' => ['required']]);
        $consent = $request->session()->pull(self::CONSENT);
        $authRequest = $this->sessionRequest($request);
        $user = $request->user();

        $workspace = ($consent['auth_token'] ?? null) === $request->input('auth_token') && isset($consent['workspace_id'])
            ? $user->workspaces()->find($consent['workspace_id']) : null;
        if (! $workspace) {
            throw ValidationException::withMessages(['agent' => 'You are not a member of any workspace, so there is no agent this client could act as.']);
        }
        if ($refused = self::refusal($request, $workspace, $user)) {
            return $refused;
        }
        if ($current->id() !== $workspace->id) {
            $now = $current->id() ? $current->get()->name : 'another workspace';
            throw ValidationException::withMessages(['agent' => "You switched to {$now} after this screen opened. Choose an agent again."]);
        }

        // A new agent and the code commit together: a failure leaves neither.
        return DB::transaction(function () use ($agents, $user, $request, $authRequest, $psrResponse) {
            $agent = $agents->forOAuthClient($user, (string) $request->input('agent'), $authRequest->getClient()->getName());
            $authRequest->setAuthorizationApproved(true);

            return AgentAccessTokenRepository::issuing($agent->id, fn () => $this->complete($authRequest, $psrResponse));
        });
    }

    public function deny(Request $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->sessionRequest($request);
        $authRequest->setAuthorizationApproved(false);

        return $this->complete($authRequest, $psrResponse);
    }

    /** What the workspace's own pages would answer instead (ResolveWorkspace, RequireTwoFactor), or agents turned off. */
    private static function refusal(Request $request, Workspace $workspace, User $user): ?Response
    {
        return ResolveWorkspace::refuse($request, $workspace, $user) ?? RequireTwoFactor::refuse($workspace, $user)
            ?? ($workspace->setting('agents_enabled') ? null : Inertia::render('Workspaces/NoAccess', [
                'message' => AgentService::disabledMessage($workspace), 'canCreate' => false, 'restoreUrl' => null,
            ])->toResponse($request)->setStatusCode(403));
    }

    /** Never skip the consent screen: it is where the agent is chosen. */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }

    private function sessionRequest(Request $request): AuthorizationRequestInterface
    {
        $authRequest = $this->getAuthRequestFromSession($request);
        abort_unless((string) $authRequest->getUser()?->getIdentifier() === (string) $request->user()->getAuthIdentifier(), 403);

        return $authRequest;
    }

    /** Back to the client's redirect URI; a full page visit, since it is another site or an app's own scheme. */
    private function complete(AuthorizationRequestInterface $authRequest, ResponseInterface $psrResponse): Response
    {
        $response = $this->withErrorHandling(fn () => $this->convertResponse($this->server->completeAuthorizationRequest($authRequest, $psrResponse)));

        return Inertia::location($response->headers->get('Location'));
    }
}
