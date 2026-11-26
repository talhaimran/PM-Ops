<?php

use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolveWorkspace;
use App\Models\User;
use App\Services\CurrentWorkspace;
use Illuminate\Support\Facades\Broadcast;

// shortcut: auth runs at subscribe only; a removed member keeps an open socket learning that the project changed and when (payloads are empty, scan F1/F2) until they reconnect; upgrade: disconnect them on member removal.
// 3-R14: people only (an agent's token never subscribes). A project channel needs a member of that workspace whose session
// the workspace serves (as on its pages), who can see the project there: accessibleProjects(), so Owners and Admins too.
Broadcast::channel('workspace.{workspace}.project.{project}', function (User $user, $workspaceId, $projectId) {
    $workspace = ! $user->viaToken() && ctype_digit((string) $workspaceId) ? $user->workspaces()->find((int) $workspaceId) : null;
    if (! $workspace || ResolveWorkspace::refuse(request(), $workspace, $user) || RequireTwoFactor::refuse($workspace, $user)) {
        return false;
    }
    app(CurrentWorkspace::class)->set($workspace);

    return ctype_digit((string) $projectId) && $user->accessibleProjects()->whereKey((int) $projectId)->exists();
});

// Not in a support session: it tells when anything reaches the user, from any of their workspaces (owner review of PR 5).
Broadcast::channel('user.{id}', fn (User $user, $id) => ! $user->viaToken() && ! request()->attributes->has('support_session') && (string) $user->id === (string) $id);
