<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Web\WorkspaceController;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use App\Services\SocialSignInService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web routes under /{workspace}: the slug picks the workspace (spec 7.0a). Unknown slug and "not a member" give the
 * same 404; an old slug 301s to the new one for members. Sets CurrentWorkspace, the URL default, the user's current
 * workspace and last_active_at (D9), then drops {workspace} so controllers keep their parameters.
 */
class ResolveWorkspace
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = (string) $route->parameter('workspace');
        $user = $request->user();

        $workspace = Workspace::firstWhere('slug', $slug);
        $renamed = ! $workspace && ($id = DB::table('workspace_slug_redirects')->where('old_slug', $slug)->value('workspace_id'));
        $workspace ??= $renamed ? Workspace::find($id) : null;
        $member = $workspace ? $user->workspaces()->whereKey($workspace->id)->first() : null;

        // 3-R19: a support session sees only the workspace it was opened for.
        $support = $request->attributes->get('support_session');
        abort_unless($member && (! $support || $support->workspace_id === $workspace->id), 404);

        if ($renamed) {
            $path = preg_replace('#^/'.preg_quote($slug, '#').'(?=/|$)#', '/'.$workspace->slug, '/'.ltrim($request->path(), '/'));

            return redirect($path.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
        }

        if ($refused = self::refuse($request, $workspace, $user)) {
            return $refused;
        }

        $this->current->set($workspace);
        URL::defaults(['workspace' => $workspace->slug]);
        $route->forgetParameter('workspace');

        // Staff viewing as someone (3-D4) change nothing of theirs, not even these.
        if (! $support && $user->current_workspace_id !== $workspace->id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        }
        $lastActive = $member->pivot->last_active_at;
        if (! $support && (! $lastActive || now()->diffInMinutes($lastActive, true) >= 5)) {
            $workspace->members()->updateExistingPivot($user->id, ['last_active_at' => now()]);
        }

        return $next($request);
    }

    /**
     * A member's session that this workspace won't serve: scheduled for deletion, archived, or signed in with a method
     * it doesn't allow. Also used by the OAuth consent screen, which sits outside /{workspace}.
     */
    public static function refuse(Request $request, Workspace $workspace, User $user): ?Response
    {
        if ($workspace->deletion_scheduled_at) {
            return WorkspaceController::scheduled($workspace, $user)->toResponse($request)->setStatusCode(403);
        }
        // 3-R19: live channels too see only a support session's own workspace.
        $session = $request->attributes->get('support_session');
        if ($session && $session->workspace_id !== $workspace->id) {
            return response('', 404);
        }
        // 3-R17: every page of an archived workspace; its Owners get Restore (not staff in a support session).
        $support = (bool) $session;
        if ($workspace->archived_at) {
            return Inertia::render('Workspaces/Archived', [
                'name' => $workspace->name,
                'restoreUrl' => ! $support && $user->can('delete', $workspace) ? route('workspaces.restore', $workspace->slug) : null,
            ])->toResponse($request)->setStatusCode(403);
        }

        // Spec 7.0f: only sessions made with a method this workspace allows. Sessions from before step 0 PR 7 were passwords.
        // A support session was verified by Platform management instead (3-R19).
        $allowed = $workspace->setting('sign_in_methods');
        if (! $support && ! in_array($request->session()->get('auth.method', 'password'), $allowed, true)) {
            return Inertia::render('Workspaces/NoAccess', [
                'message' => "{$workspace->name} allows sign-in with ".SocialSignInService::describe($allowed).'. Sign out, then sign in that way.',
                'canCreate' => false,
                'restoreUrl' => null,
            ])->toResponse($request)->setStatusCode(403);
        }

        return null;
    }
}
