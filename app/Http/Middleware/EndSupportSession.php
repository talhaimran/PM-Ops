<?php

namespace App\Http\Middleware;

use App\Models\SupportSession;
use App\Services\SupportSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 3-R19: on every web request of a support session, signs out at ends_at (or on Log out) and logs
 * staff.support_session_ended once; otherwise marks the request as a support session (the support_session request
 * attribute), which makes it read-only, skips the workspace's 2FA and sign-in-method rules, and shows the banner.
 * Signs out without the Logout event: the target didn't sign out. Outside /{workspace} it serves only the few routes
 * that lead back into it.
 */
class EndSupportSession
{
    public function __construct(private SupportSessionService $support) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->hasSession() ? $request->session()->get(SupportSessionService::SESSION_KEY) : null;
        if (! $id) {
            return $next($request);
        }

        $session = SupportSession::with(['workspace', 'target'])->find($id);
        if ($session && ! $session->ended_at && $session->ends_at->isFuture() && ! $request->routeIs('logout')) {
            $request->attributes->set('support_session', $session);
            // Scan F1/F2: only its workspace's pages, never the user's account pages, OAuth or other workspaces.
            abort_unless($this->allowed($request), 404);

            return $next($request);
        }

        if ($session) {
            $this->support->end($session);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::guard('web')->forgetUser();

        return match (true) {
            $request->routeIs('logout') => redirect('/'),
            // An ended session in this browser doesn't stop a new support URL from being opened.
            $request->routeIs('support-sessions.consume') => $next($request),
            default => redirect()->route('login'),
        };
    }

    private function allowed(Request $request): bool
    {
        $route = $request->route();

        return in_array('workspace', $route?->middleware() ?? [], true)
            || $request->routeIs('support-sessions.consume', 'workspaces.no-access')
            || $request->is('/', 'broadcasting/auth')
            || $route?->uri() === '{legacy}';
    }
}
