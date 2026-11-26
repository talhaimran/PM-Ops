<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 3-R16 / 3-D4: the one write check. A support session never writes; a suspended or archived workspace
 * (Workspace::isWritable) takes no writes from anyone. Every web request (the web group) and REST call with an unsafe
 * method passes handle(); MCP write tools (no #[IsReadOnly]) over HTTP and stdio pass refusal() in PmServer.
 * Not writes: live-channel auth, signing out (EndSupportSession ends a support session first), and marking your own
 * notifications read in a suspended workspace (that is reading).
 */
class EnsureWorkspaceWritable
{
    public const MESSAGES = [
        'suspended' => 'This workspace is suspended. You can view and export, but not make changes.',
        'archived' => 'This workspace was archived after 12 months without activity. An Owner can restore it in the web app.',
        'support_session' => 'This is a read-only support session. Nothing can be changed.',
    ];

    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $support = $request->attributes->has('support_session');
        // GET too in a support session: the Google/GitHub callback links that login to whoever is signed in.
        $write = ! $request->isMethodSafe() || ($support && $request->routeIs('social.*'));
        if (! $write || $request->is('broadcasting/auth') || $request->routeIs('logout')) {
            return $next($request);
        }
        // Only routes that act in a workspace (web /{workspace}, REST) check its state; account pages outside it don't.
        $route = $request->route();
        $inWorkspace = $this->current->id() && ! $request->routeIs('notifications.read', 'notifications.read-all')
            && array_intersect(['workspace', ResolveTokenWorkspace::class], $route?->middleware() ?? []);
        $refusal = self::refusal($inWorkspace ? $this->current->get() : null, $support);
        if (! $refusal) {
            return $next($request);
        }

        return $request->expectsJson() || $request->is('api/*')
            ? response()->json($refusal, 403)
            : back()->with('warning', $refusal['message']);
    }

    /** @return array{error: string, message: string}|null why this write is refused, or null when it may go ahead */
    public static function refusal(?Workspace $workspace, bool $supportSession = false): ?array
    {
        return match (true) {
            $supportSession => ['error' => 'support_session_read_only', 'message' => self::MESSAGES['support_session']],
            $workspace && ! $workspace->isWritable() => ['error' => "workspace_{$workspace->state()}", 'message' => self::MESSAGES[$workspace->state()]],
            default => null,
        };
    }
}
