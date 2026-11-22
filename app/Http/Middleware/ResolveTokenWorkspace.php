<?php

namespace App\Http\Middleware;

use App\Services\AgentService;
use App\Services\CurrentWorkspace;
use App\Services\WorkspaceService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * API and MCP over HTTP act in the token's workspace (spec 7.0a); an OAuth token in its agent's. A token whose owner has left that workspace has its
 * agent revoked and the request is refused; a paused agent gets agent_paused. Session-authenticated calls use the
 * current workspace.
 */
class ResolveTokenWorkspace
{
    public function __construct(private CurrentWorkspace $current, private WorkspaceService $workspaces, private AgentService $agents) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        $oauth = $user?->viaToken() && ! $token instanceof PersonalAccessToken;
        if (($token instanceof PersonalAccessToken && $token->exists) || $oauth) {
            // Every token is an agent's since step A; one without (made outside AgentService or the OAuth consent
            // screen) is never accepted. An OAuth token works in its agent's workspace (A-D3).
            $agent = $user->agent();
            if (! $agent || $agent->owner_user_id !== $user->id) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            $workspaceId = $oauth ? $agent->workspace_id : $token->workspace_id;
            $workspace = $workspaceId ? $user->workspaces()->find($workspaceId) : null;

            if (! $workspace) {
                $this->agents->revoke($agent, null, 'owner_left');

                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            if ($workspace->deletion_scheduled_at) {
                return response()->json(['message' => $this->workspaces->lockedMessage($workspace, $user)], 403);
            }
            $this->agents->ensureUsable($agent);
        } else {
            $workspace = $user ? $this->workspaces->currentFor($user) : null;
        }

        $workspace ? $this->current->set($workspace) : $this->current->forget();

        return $next($request);
    }
}
