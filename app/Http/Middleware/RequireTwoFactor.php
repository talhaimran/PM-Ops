<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 7.0e: a workspace with require_2fa sends members without two-factor to set it up. Runs after ResolveWorkspace
 * on /{workspace} pages only, so the profile, sign-out and other workspaces stay reachable. API tokens are unaffected.
 */
class RequireTwoFactor
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        return self::refuse($this->current->get(), $request->user()) ?? $next($request);
    }

    /** To the profile to set up two-factor; also used by the OAuth consent screen, which sits outside /{workspace}. */
    public static function refuse(Workspace $workspace, User $user): ?Response
    {
        return $workspace->setting('require_2fa') && ! $user->hasEnabledTwoFactorAuthentication()
            ? redirect()->route('profile.edit')->with('status', "{$workspace->name} requires two-factor authentication. Turn it on below to continue.")
            : null;
    }
}
