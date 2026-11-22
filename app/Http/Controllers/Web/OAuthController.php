<?php

namespace App\Http\Controllers\Web;

use App\Mcp\OAuth\AgentAccessTokenRepository;
use App\Services\AgentService;
use App\Services\CurrentWorkspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
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

    public function authorize(ServerRequestInterface $psrRequest, Request $request, ResponseInterface $psrResponse, AuthorizationViewResponse $viewResponse): Response|AuthorizationViewResponse
    {
        // league also takes "plain", and no PKCE at all from a confidential client.
        if ($request->query('code_challenge_method') !== 'S256' || ! $request->filled('code_challenge')) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'PKCE is required: send code_challenge with code_challenge_method=S256.'], 400);
        }

        return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
    }

    /** The consent screen (Passport's authorization view). */
    public static function consent(array $params): Response
    {
        $workspace = app(CurrentWorkspace::class);
        $agents = app(AgentService::class);

        return Inertia::render('OAuth/Authorize', [
            'client' => $params['client']->name,
            'workspace' => $workspace->id() ? $workspace->get()->name : null,
            'agents' => $agents->oauthChoices($params['user']),
            'newAgent' => AgentService::oauthAgentName($params['user'], $params['client']->name),
            'authToken' => $params['authToken'],
        ])->toResponse($params['request']);
    }

    public function approve(Request $request, ResponseInterface $psrResponse, AgentService $agents): Response
    {
        $request->validate(['agent' => ['required']]);
        $authRequest = $this->sessionRequest($request);
        // Before the code exists, so a refused choice leaves no code: the error goes back to the screen, which starts
        // a fresh authorization request from the same URL.
        $agent = $agents->forOAuthClient($request->user(), (string) $request->input('agent'), $authRequest->getClient()->getName());
        AgentAccessTokenRepository::useAgent($agent->id);
        $authRequest->setAuthorizationApproved(true);

        return $this->complete($authRequest, $psrResponse);
    }

    public function deny(Request $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->sessionRequest($request);
        $authRequest->setAuthorizationApproved(false);

        return $this->complete($authRequest, $psrResponse);
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
