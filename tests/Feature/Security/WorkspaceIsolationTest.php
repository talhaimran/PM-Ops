<?php

use App\Enums\AgentRunChangeStatus;
use App\Enums\AgentRunStatus;
use App\Enums\AgentStatus;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Events\AgentRunChanged;
use App\Mcp\Servers\LocalPmServer;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentRunChange;
use App\Models\AiProvider;
use App\Models\Column;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentService;
use App\Services\ApprovalService;
use App\Services\ColumnService;
use App\Services\CommentService;
use App\Services\CurrentWorkspace;
use App\Services\InvitationService;
use App\Services\LabelService;
use App\Services\NotificationService;
use App\Services\SprintService;
use App\Services\StatusService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\AuthCode;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Spec 7.0a: acting in workspace A, nothing of workspace B is ever shown or changed, and B's ids and keys
 * get the same error as ids and keys that don't exist. ann is in A and B (and Admin of B's projects, with Delete-level
 * agents, so only the workspace boundary stops her); bob is only in B. B's text all carries the marker "B-SECRET".
 *
 * Every registered route and MCP tool needs a case below or a reasoned exemption; a new one fails
 * the coverage test until it gets one.
 */

const B_MARKER = 'B-SECRET';

/** Placeholders: {P} project key, {I} issue key, {C} comment id, {COL}/{COLN} column id/name, {L}/{LN} label id/name, {S}/{SN} sprint id/name, {T} token id, {INV} invitation id, {AR} approval request id, {N} notification id, {U} user id, {E} member email, {RUN}/{RCH} agent run/change id. */
function isolationRouteCases(): array
{
    $confirm = ['confirm' => true];

    return [
        // REST API, as ann with her workspace A token.
        'PATCH api/v1/comments/{comment}' => ['api/v1/comments/{C}', ['body' => 'x']],
        'DELETE api/v1/comments/{comment}' => ['api/v1/comments/{C}', $confirm],
        'GET api/v1/issues' => ['api/v1/issues?q=secret', []],
        'PATCH api/v1/issues/bulk' => ['api/v1/issues/bulk', ['updates' => [['key' => '{I}', 'title' => 'x']]]],
        'GET api/v1/issues/{key}' => ['api/v1/issues/{I}', []],
        'PATCH api/v1/issues/{key}' => ['api/v1/issues/{I}', ['title' => 'x']],
        'DELETE api/v1/issues/{key}' => ['api/v1/issues/{I}', $confirm],
        'GET api/v1/issues/{key}/activity' => ['api/v1/issues/{I}/activity', []],
        'POST api/v1/issues/{key}/comments' => ['api/v1/issues/{I}/comments', ['body' => 'x']],
        'POST api/v1/issues/{key}/move' => ['api/v1/issues/{I}/move', ['status' => '{COLN}']],
        'POST api/v1/issues/{key}/rank' => ['api/v1/issues/{I}/rank', ['sprint' => '{SN}']],
        // Links: B's issue as the target (from A's WEB-1), and as the issue in the URL.
        'POST api/v1/issues/{key}/clone' => ['api/v1/issues/{I}/clone', ['links' => true]],
        'POST api/v1/issues/{key}/links' => ['api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => '{I}']],
        'DELETE api/v1/issues/{key}/links' => ['api/v1/issues/{I}/links', ['type' => 'blocks', 'to' => 'WEB-1']],
        'POST api/v1/comments/{comment}/reactions' => ['api/v1/comments/{C}/reactions', ['emoji' => '👍']],
        'DELETE api/v1/comments/{comment}/reactions' => ['api/v1/comments/{C}/reactions', ['emoji' => '👍']],
        'POST api/v1/issues/{key}/watchers' => ['api/v1/issues/{I}/watchers', []],
        'DELETE api/v1/issues/{key}/watchers' => ['api/v1/issues/{I}/watchers', []],
        'GET api/v1/me/issues' => ['api/v1/me/issues', []],
        // Take no id: whoami names only the token's own workspace; updates are only the calling agent's, in A.
        // Not compared: the rate-limit counter it reports differs between any two calls.
        'GET api/v1/me' => ['api/v1/me', [], false],
        'GET api/v1/me/updates' => ['api/v1/me/updates', []],
        'GET api/v1/projects' => ['api/v1/projects', []],
        // B has OPS too: creating it in A must work (keys are unique per workspace) and reveal nothing.
        'POST api/v1/projects' => ['api/v1/projects', ['key' => '{P}', 'name' => 'x'], false],
        'GET api/v1/projects/{key}' => ['api/v1/projects/{P}', []],
        'PATCH api/v1/projects/{key}' => ['api/v1/projects/{P}', ['name' => 'x']],
        'DELETE api/v1/projects/{key}' => ['api/v1/projects/{P}', $confirm],
        'GET api/v1/projects/{key}/backlog' => ['api/v1/projects/{P}/backlog', []],
        'GET api/v1/projects/{key}/board' => ['api/v1/projects/{P}/board', []],
        'GET api/v1/projects/{key}/columns' => ['api/v1/projects/{P}/columns', []],
        'POST api/v1/projects/{key}/columns' => ['api/v1/projects/{P}/columns', ['name' => 'x']],
        'PATCH api/v1/projects/{key}/columns/{name}' => ['api/v1/projects/{P}/columns/{COLN}', ['name' => 'x']],
        'DELETE api/v1/projects/{key}/columns/{name}' => ['api/v1/projects/{P}/columns/{COLN}', $confirm],
        'POST api/v1/projects/{key}/invitations' => ['api/v1/projects/{P}/invitations', ['email' => 'x@example.com', 'role' => 'member']],
        'GET api/v1/projects/{key}/issues' => ['api/v1/projects/{P}/issues', []],
        'POST api/v1/projects/{key}/issues' => ['api/v1/projects/{P}/issues', ['title' => 'x']],
        'POST api/v1/projects/{key}/issues/bulk' => ['api/v1/projects/{P}/issues/bulk', ['issues' => [['title' => 'x']]]],
        'GET api/v1/projects/{key}/labels' => ['api/v1/projects/{P}/labels', []],
        'POST api/v1/projects/{key}/labels' => ['api/v1/projects/{P}/labels', ['name' => 'x']],
        'PATCH api/v1/projects/{key}/labels/{name}' => ['api/v1/projects/{P}/labels/{LN}', ['name' => 'x']],
        'DELETE api/v1/projects/{key}/labels/{name}' => ['api/v1/projects/{P}/labels/{LN}', $confirm],
        'GET api/v1/projects/{key}/members' => ['api/v1/projects/{P}/members', []],
        'PATCH api/v1/projects/{key}/members/{email}' => ['api/v1/projects/{P}/members/{E}', ['role' => 'viewer']],
        'DELETE api/v1/projects/{key}/members/{email}' => ['api/v1/projects/{P}/members/{E}', $confirm],
        'GET api/v1/projects/{key}/sprints' => ['api/v1/projects/{P}/sprints', []],
        'POST api/v1/projects/{key}/sprints' => ['api/v1/projects/{P}/sprints', []],
        'GET api/v1/projects/{key}/sprints/{sprint}' => ['api/v1/projects/{P}/sprints/{SN}', []],
        'PATCH api/v1/projects/{key}/sprints/{sprint}' => ['api/v1/projects/{P}/sprints/{SN}', ['goal' => 'x']],
        'DELETE api/v1/projects/{key}/sprints/{sprint}' => ['api/v1/projects/{P}/sprints/{SN}', $confirm],
        'POST api/v1/projects/{key}/sprints/{sprint}/complete' => ['api/v1/projects/{P}/sprints/{SN}/complete', ['confirm' => true, 'move_open_issues_to' => 'backlog']],
        'POST api/v1/projects/{key}/sprints/{sprint}/start' => ['api/v1/projects/{P}/sprints/{SN}/start', []],
        'GET api/v1/projects/{key}/summary' => ['api/v1/projects/{P}/summary', []],

        // Web, as ann signed in, inside workspace A's URL prefix.
        'PATCH {workspace}/comments/{comment}' => ['alpha/comments/{C}', ['body' => 'x']],
        'DELETE {workspace}/comments/{comment}' => ['alpha/comments/{C}', []],
        'POST {workspace}/comments/{comment}/restore' => ['alpha/comments/{C}/restore', []],
        'GET {workspace}/dashboard' => ['alpha/dashboard', []],
        'POST {workspace}/approvals/{approval}/approve' => ['alpha/approvals/{AR}/approve', ['confirm_changed' => true]],
        'POST {workspace}/approvals/{approval}/reject' => ['alpha/approvals/{AR}/reject', ['note' => 'x']],
        // Step B: B's issue and ann's B agent; then ann's own run in B, and (child in A) her run in A with B's change.
        'POST {workspace}/issues/{key}/runs' => ['alpha/issues/{I}/runs', ['agent' => '{T}', 'instruction' => 'x']],
        'POST {workspace}/runs/{run}/cancel' => ['alpha/runs/{RUN}/cancel', []],
        'POST {workspace}/runs/{run}/apply' => ['alpha/runs/{RUN}/apply', []],
        'POST {workspace}/runs/{run}/discard' => ['alpha/runs/{RUN}/discard', []],
        'POST {workspace}/runs/{run}/changes/{change}/apply' => ['alpha/runs/{RUN}/changes/{RCH}/apply', []],
        'POST {workspace}/runs/{run}/changes/{change}/discard' => ['alpha/runs/{RUN}/changes/{RCH}/discard', []],
        'GET {workspace}/notifications' => ['alpha/notifications', []],
        'POST {workspace}/notifications/read-all' => ['alpha/notifications/read-all', []],
        'POST {workspace}/notifications/{notification}/read' => ['alpha/notifications/{N}/read', []],
        'GET {workspace}/search' => ['alpha/search?q=secret', []],
        'GET {workspace}/issues/{key}' => ['alpha/issues/{I}', []],
        'PATCH {workspace}/issues/{key}' => ['alpha/issues/{I}', ['title' => 'x']],
        'DELETE {workspace}/issues/{key}' => ['alpha/issues/{I}', []],
        'POST {workspace}/issues/{key}/comments' => ['alpha/issues/{I}/comments', ['body' => 'x']],
        'POST {workspace}/issues/{key}/move' => ['alpha/issues/{I}/move', ['status' => '{COLN}']],
        'POST {workspace}/issues/{key}/rank' => ['alpha/issues/{I}/rank', ['sprint' => '{SN}']],
        'POST {workspace}/issues/{key}/restore' => ['alpha/issues/{I}/restore', []],
        'POST {workspace}/issues/{key}/clone' => ['alpha/issues/{I}/clone', ['links' => true]],
        'POST {workspace}/issues/{key}/links' => ['alpha/issues/WEB-1/links', ['type' => 'blocks', 'to' => '{I}']],
        'DELETE {workspace}/issues/{key}/links' => ['alpha/issues/{I}/links', ['type' => 'blocks', 'to' => 'WEB-1']],
        'POST {workspace}/comments/{comment}/reactions' => ['alpha/comments/{C}/reactions', ['emoji' => '👍']],
        'DELETE {workspace}/comments/{comment}/reactions' => ['alpha/comments/{C}/reactions', ['emoji' => '👍']],
        'GET {workspace}/projects/{project}/mentionable' => ['alpha/projects/{P}/mentionable', []],
        'POST {workspace}/issues/{key}/watchers' => ['alpha/issues/{I}/watchers', []],
        'DELETE {workspace}/issues/{key}/watchers' => ['alpha/issues/{I}/watchers', []],
        'GET {workspace}/projects' => ['alpha/projects', []],
        'POST {workspace}/projects' => ['alpha/projects', ['key' => '{P}', 'name' => 'x'], false],
        'GET {workspace}/projects/{project}' => ['alpha/projects/{P}', []],
        'PATCH {workspace}/projects/{project}' => ['alpha/projects/{P}', ['name' => 'x']],
        'DELETE {workspace}/projects/{project}' => ['alpha/projects/{P}', $confirm],
        'GET {workspace}/projects/{project}/backlog' => ['alpha/projects/{P}/backlog', []],
        'POST {workspace}/projects/{project}/columns' => ['alpha/projects/{P}/columns', ['name' => 'x']],
        'POST {workspace}/projects/{project}/columns/{column}/statuses' => ['alpha/projects/{P}/columns/{COL}/statuses', ['status' => '{STN}']],
        'DELETE {workspace}/projects/{project}/columns/{column}/statuses/{status}' => ['alpha/projects/{P}/columns/{COL}/statuses/{ST}', []],
        'PATCH {workspace}/projects/{project}/columns/{column}' => ['alpha/projects/{P}/columns/{COL}', ['name' => 'x']],
        'DELETE {workspace}/projects/{project}/columns/{column}' => ['alpha/projects/{P}/columns/{COL}', []],
        'POST {workspace}/projects/{project}/invitations' => ['alpha/projects/{P}/invitations', ['email' => 'x@example.com', 'role' => 'member']],
        'DELETE {workspace}/projects/{project}/invitations/{invitation}' => ['alpha/projects/{P}/invitations/{INV}', []],
        'GET {workspace}/projects/{project}/issues' => ['alpha/projects/{P}/issues', []],
        'POST {workspace}/projects/{project}/issues' => ['alpha/projects/{P}/issues', ['title' => 'x']],
        'POST {workspace}/projects/{project}/labels' => ['alpha/projects/{P}/labels', ['name' => 'x']],
        'PATCH {workspace}/projects/{project}/labels/{label}' => ['alpha/projects/{P}/labels/{L}', ['name' => 'x']],
        'DELETE {workspace}/projects/{project}/labels/{label}' => ['alpha/projects/{P}/labels/{L}', []],
        'PATCH {workspace}/projects/{project}/members/{user}' => ['alpha/projects/{P}/members/{U}', ['role' => 'viewer']],
        'DELETE {workspace}/projects/{project}/members/{user}' => ['alpha/projects/{P}/members/{U}', []],
        'GET {workspace}/projects/{project}/settings' => ['alpha/projects/{P}/settings', []],
        'POST {workspace}/projects/{project}/join' => ['alpha/projects/{P}/join', []],
        'POST {workspace}/projects/{project}/sprints' => ['alpha/projects/{P}/sprints', []],
        'PATCH {workspace}/projects/{project}/sprints/{sprint}' => ['alpha/projects/{P}/sprints/{S}', ['goal' => 'x']],
        'DELETE {workspace}/projects/{project}/sprints/{sprint}' => ['alpha/projects/{P}/sprints/{S}', []],
        'POST {workspace}/projects/{project}/sprints/{sprint}/complete' => ['alpha/projects/{P}/sprints/{S}/complete', ['confirm' => true, 'move_open_issues_to' => 'backlog']],
        'POST {workspace}/projects/{project}/sprints/{sprint}/start' => ['alpha/projects/{P}/sprints/{S}/start', []],
        // The old tokens page only redirects.
        'GET {workspace}/settings/tokens' => ['alpha/settings/tokens', []],
        'GET {workspace}/settings/agents' => ['alpha/settings/agents', []],
        'POST {workspace}/settings/agents' => ['alpha/settings/agents', ['name' => 'x', 'level' => 'read', 'projects' => ['{P}']]],
        'PATCH {workspace}/settings/agents/{agent}' => ['alpha/settings/agents/{T}', ['name' => 'x', 'level' => 'read']],
        'POST {workspace}/settings/agents/{agent}/pause' => ['alpha/settings/agents/{T}/pause', []],
        'POST {workspace}/settings/agents/{agent}/resume' => ['alpha/settings/agents/{T}/resume', []],
        'POST {workspace}/settings/agents/{agent}/regenerate' => ['alpha/settings/agents/{T}/regenerate', []],
        'DELETE {workspace}/settings/agents/{agent}' => ['alpha/settings/agents/{T}', []],
        'PATCH {workspace}/settings/agent-policy' => ['alpha/settings/agent-policy', ['agents_enabled' => true]],
        'GET {workspace}/settings/ai' => ['alpha/settings/ai', []],
        'PATCH {workspace}/settings/ai' => ['alpha/settings/ai', ['provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'sk-alpha']],
        'DELETE {workspace}/settings/ai' => ['alpha/settings/ai', []],
        'PATCH {workspace}/settings/ai/cap' => ['alpha/settings/ai/cap', ['monthly_token_cap' => 5]],
        'PATCH {workspace}/settings/ai/mode-c' => ['alpha/settings/ai/mode-c', ['mode_c_enabled' => false]],
        'GET {workspace}/settings/general' => ['alpha/settings/general', []],
        'PATCH {workspace}/settings/general' => ['alpha/settings/general', ['name' => 'Alpha', 'slug' => 'alpha', 'members_can_create_projects' => true]],
        // A wrong confirmation: deleting A for real would end the remaining cases.
        'DELETE {workspace}/settings/general' => ['alpha/settings/general', ['confirm_slug' => '{P}']],
        'GET {workspace}/settings/members' => ['alpha/settings/members', []],
        // Filtering A's log by a user of B shows nothing of B.
        'GET {workspace}/settings/statuses' => ['alpha/settings/statuses', []],
        'PATCH {workspace}/settings/statuses/{status}' => ['alpha/settings/statuses/{ST}', ['name' => 'x']],
        'POST {workspace}/settings/statuses/{status}/merge' => ['alpha/settings/statuses/{ST}/merge', ['into' => '{STN}']],
        'DELETE {workspace}/settings/statuses/{status}' => ['alpha/settings/statuses/{ST}', []],
        'GET {workspace}/settings/audit-log' => ['alpha/settings/audit-log?actor={U}', []],
        'GET {workspace}/settings/audit-log/export' => ['alpha/settings/audit-log/export?actor={U}', []],
        // Takes no id: it must count only A's people and projects (B has more of both).
        'GET {workspace}/settings/usage' => ['alpha/settings/usage', []],
        // Takes no id: the redirect token (fresh nonce each time, so not compared) names A only; B's slug is the separate test below.
        'GET {workspace}/settings/billing' => ['alpha/settings/billing', [], false],
        'GET {workspace}/settings/security' => ['alpha/settings/security', []],
        'PATCH {workspace}/settings/security' => ['alpha/settings/security', ['require_2fa' => false]],
        'PATCH {workspace}/settings/sign-in-methods' => ['alpha/settings/sign-in-methods', ['sign_in_methods' => ['password']]],
        'POST {workspace}/settings/members/invitations' => ['alpha/settings/members/invitations', ['email' => 'x@example.com', 'role' => 'member', 'project' => '{P}']],
        'POST {workspace}/settings/members/invitations/{invitation}/resend' => ['alpha/settings/members/invitations/{INV}/resend', []],
        'DELETE {workspace}/settings/members/invitations/{invitation}' => ['alpha/settings/members/invitations/{INV}', []],
        'PATCH {workspace}/settings/members/{user}' => ['alpha/settings/members/{U}', ['role' => 'member']],
        'DELETE {workspace}/settings/members/{user}' => ['alpha/settings/members/{U}', []],
    ];
}

/** Routes that never take a workspace object's id or key, with the reason. */
function isolationExemptRoutes(): array
{
    $auth = 'account sign-in flow, no workspace data';

    return [
        'GET /' => 'public welcome page',
        'GET _scramble/dev-tools/devtools.js' => 'API docs asset',
        'GET docs/api' => 'API docs', 'GET docs/api.json' => 'API docs',
        'GET up' => 'health check', 'GET sanctum/csrf-cookie' => 'CSRF cookie',
        'GET storage/{path}' => 'framework file route', 'PUT storage/{path}' => 'framework file route',
        'GET mcp' => 'MCP transport; every tool is covered below', 'POST mcp' => 'MCP transport; every tool is covered below', 'DELETE mcp' => 'MCP transport; every tool is covered below',
        'GET profile' => 'account page, global by D10', 'PATCH profile' => 'account page, global by D10', 'DELETE profile' => 'account page, global by D10',
        'PUT password' => $auth,
        'GET two-factor-challenge' => $auth, 'POST two-factor-challenge' => $auth,
        'GET auth/{provider}/redirect' => $auth, 'GET auth/{provider}/callback' => $auth,
        'DELETE profile/social/{provider}' => 'account sign-in methods, global by D10',
        'POST profile/two-factor' => 'account 2FA, global by D10', 'POST profile/two-factor/confirm' => 'account 2FA, global by D10',
        'POST profile/two-factor/recovery-codes' => 'account 2FA, global by D10', 'DELETE profile/two-factor' => 'account 2FA, global by D10',
        // Invitations belong to the invitee, who may accept one from any workspace (InvitationTest, HardeningTest).
        'POST invitations/{invitation}/accept' => 'addressed to the invitee across workspaces',
        'GET invitations/{token}' => 'addressed by the emailed token, not a workspace id; InvitationTest',
        'POST invitations/{token}' => 'addressed by the emailed token, not a workspace id; InvitationTest',
        'GET {legacy}' => 'redirects into the current workspace only; UrlPrefixTest',
        'GET workspaces/create' => 'creates a new workspace; EditionTest', 'POST workspaces' => 'creates a new workspace; EditionTest',
        'GET workspaces/no-access' => 'shown only with no workspace; WorkspaceSwitchTest',
        'POST workspaces/{slug}/restore' => "finds the slug among the user's own workspaces, Owner only; WorkspaceDeletionTest",
        'GET confirm-password' => $auth, 'POST confirm-password' => $auth, 'POST email/verification-notification' => $auth,
        'GET forgot-password' => $auth, 'POST forgot-password' => $auth, 'GET login' => $auth, 'POST login' => $auth,
        'POST logout' => $auth, 'GET register' => $auth, 'POST register' => $auth, 'POST reset-password' => $auth,
        'GET reset-password/{token}' => $auth, 'GET verify-email' => $auth, 'GET verify-email/{id}/{hash}' => $auth,
        // A-D3 OAuth: discovery and client registration hold no workspace data; the token works in its agent's
        // workspace and the consent screen lists only the current one's agents (cases below, McpOAuthSecurityTest).
        'GET .well-known/oauth-protected-resource' => 'OAuth discovery metadata', 'GET .well-known/oauth-protected-resource/{path}' => 'OAuth discovery metadata',
        'GET .well-known/oauth-authorization-server' => 'OAuth discovery metadata', 'GET .well-known/oauth-authorization-server/{path}' => 'OAuth discovery metadata',
        'POST oauth/register' => 'OAuth client registration, no workspace data', 'POST oauth/token' => 'OAuth code exchange; the agent was picked on consent',
        'GET oauth/authorize' => 'consent screen; lists only the current workspace\'s agents (below)',
        'POST oauth/authorize' => 'consent; takes only my agents in the current workspace (below)', 'DELETE oauth/authorize' => 'consent refused; changes nothing',
        // 3-R14: the channel names carry the ids; the channel cases below check them.
        'GET broadcasting/auth' => 'live channel auth; channels covered below', 'POST broadcasting/auth' => 'live channel auth; channels covered below',
        // 3-R18: the service API takes only PM_INTERNAL_API_TOKEN(_NEXT); a user's token or session is a 401 (below, AdminApiSecurityTest).
        ...collect([
            'GET internal/v1/workspaces', 'GET internal/v1/workspaces/{id}', 'GET internal/v1/workspaces/{id}/usage',
            'PUT internal/v1/workspaces/{id}/entitlements', 'PUT internal/v1/workspaces/{id}/entitlements/{key}', 'DELETE internal/v1/workspaces/{id}/entitlements/{key}',
            'POST internal/v1/workspaces/{id}/suspend', 'POST internal/v1/workspaces/{id}/unsuspend', 'POST internal/v1/workspaces/{id}/archive',
            'POST internal/v1/workspaces/{id}/restore', 'POST internal/v1/workspaces/{id}/support-sessions',
        ])->mapWithKeys(fn ($r) => [$r => 'internal admin API, service token only (below)'])->all(),
        // 3-R19: addressed by a one-time token; the session it opens stays in its own workspace (below, SupportSessionTest).
        'GET support/{token}' => 'one-time support-session URL; stays in its workspace (below)',
    ];
}

function isolationToolCases(): array
{
    $confirm = ['confirm' => true];

    return [
        Tools\ListProjects::class => [],
        Tools\GetProject::class => ['project' => '{P}'],
        Tools\CreateProject::class => ['key' => '{P}', 'name' => 'x', '_compare' => false],
        Tools\UpdateProject::class => ['project' => '{P}', 'name' => 'x'],
        Tools\DeleteProject::class => ['project' => '{P}', ...$confirm],
        Tools\CreateIssue::class => ['project' => '{P}', 'title' => 'x'],
        Tools\GetIssue::class => ['issue' => '{I}'],
        Tools\UpdateIssue::class => ['issue' => '{I}', 'title' => 'x'],
        Tools\DeleteIssue::class => ['issue' => '{I}', ...$confirm],
        Tools\GetIssueActivity::class => ['issue' => '{I}'],
        Tools\CloneIssue::class => ['key' => '{I}', 'links' => true],
        Tools\LinkIssues::class => ['from' => 'WEB-1', 'type' => 'blocks', 'to' => '{I}'],
        Tools\UnlinkIssues::class => ['from' => '{I}', 'type' => 'blocks', 'to' => 'WEB-1'],
        Tools\WatchIssue::class => ['issue' => '{I}'],
        Tools\UnwatchIssue::class => ['issue' => '{I}'],
        Tools\AddComment::class => ['issue' => '{I}', 'body' => 'x'],
        Tools\UpdateComment::class => ['comment_id' => '{C}', 'body' => 'x'],
        Tools\AddReaction::class => ['comment_id' => '{C}', 'emoji' => '👍'],
        Tools\RemoveReaction::class => ['comment_id' => '{C}', 'emoji' => '👍'],
        Tools\DeleteComment::class => ['comment_id' => '{C}', ...$confirm],
        Tools\ListLabels::class => ['project' => '{P}'],
        Tools\CreateLabel::class => ['project' => '{P}', 'name' => 'x'],
        Tools\UpdateLabel::class => ['project' => '{P}', 'label' => '{LN}', 'name' => 'x'],
        Tools\DeleteLabel::class => ['project' => '{P}', 'label' => '{LN}', ...$confirm],
        Tools\GetBoard::class => ['project' => '{P}'],
        Tools\MoveIssue::class => ['issue' => '{I}', 'status' => '{COLN}'],
        Tools\ListColumns::class => ['project' => '{P}'],
        // {COLN} is also the name of B's status for that column: it must not be found from A (Review Focus 4).
        Tools\CreateColumn::class => ['project' => '{P}', 'name' => 'x', 'status' => '{COLN}'],
        Tools\UpdateColumn::class => ['project' => '{P}', 'column' => '{COLN}', 'name' => 'x'],
        Tools\DeleteColumn::class => ['project' => '{P}', 'column' => '{COLN}', ...$confirm],
        Tools\ListSprints::class => ['project' => '{P}'],
        Tools\CreateSprint::class => ['project' => '{P}'],
        Tools\UpdateSprint::class => ['project' => '{P}', 'sprint' => '{SN}', 'goal' => 'x'],
        Tools\DeleteSprint::class => ['project' => '{P}', 'sprint' => '{SN}', ...$confirm],
        Tools\StartSprint::class => ['project' => '{P}', 'sprint' => '{SN}'],
        Tools\CompleteSprint::class => ['project' => '{P}', 'sprint' => '{SN}', 'move_open_issues_to' => 'backlog', ...$confirm],
        Tools\GetBacklog::class => ['project' => '{P}'],
        Tools\RankBacklog::class => ['issue' => '{I}', 'sprint' => '{SN}'],
        Tools\SearchIssues::class => ['q' => 'secret'],
        Tools\MyIssues::class => [],
        Tools\Whoami::class => [],
        Tools\GetUpdates::class => [],
        Tools\ProjectSummary::class => ['project' => '{P}'],
        Tools\BulkCreateIssues::class => ['project' => '{P}', 'issues' => [['title' => 'x']]],
        Tools\BulkUpdateIssues::class => ['updates' => [['key' => '{I}', 'title' => 'x']]],
    ];
}

const CHILD_PLACEHOLDERS = ['{C}', '{COL}', '{COLN}', '{L}', '{LN}', '{S}', '{SN}', '{T}', '{INV}', '{ST}', '{STN}', '{RCH}'];

/** @return array<string, array<string, string>> variant name => placeholder values; paired variants must answer alike. */
function isolationVariants($t): array
{
    $b = [
        '{P}' => 'OPS', '{I}' => 'OPS-1', '{C}' => (string) $t->bComment->id, '{COL}' => (string) $t->bColumn->id, '{COLN}' => 'B-SECRET col',
        '{L}' => (string) $t->bLabel->id, '{LN}' => 'b-secret', '{S}' => (string) $t->bSprint->id, '{SN}' => 'B-SECRET sprint',
        '{T}' => (string) $t->annB[0]->id, '{INV}' => (string) $t->bInvitation->id, '{U}' => (string) $t->bob->id, '{E}' => $t->bob->email,
        '{AR}' => (string) $t->bApproval->id, '{N}' => (string) $t->bNotification->id, '{ST}' => (string) $t->bStatus->id, '{STN}' => $t->bStatus->name,
        '{RUN}' => (string) $t->bRun->id, '{RCH}' => (string) $t->bRunChange->id,
    ];
    $missing = [
        '{P}' => 'NOPE', '{I}' => 'NOPE-1', '{C}' => '999999', '{COL}' => '999998', '{COLN}' => 'No such col',
        '{L}' => '999997', '{LN}' => 'no-such', '{S}' => '999996', '{SN}' => 'No such sprint',
        '{T}' => '999995', '{INV}' => '999994', '{U}' => '999993', '{E}' => 'nobody@example.com',
        '{AR}' => '999992', '{N}' => '999990', '{ST}' => '999991', '{STN}' => 'No such status',
        '{RUN}' => '999989', '{RCH}' => '999988',
    ];
    $inA = ['{P}' => 'WEB', '{I}' => 'WEB-1', '{RUN}' => (string) $t->aRun->id];

    return [
        'b' => $b, 'missing' => $missing,
        'b child in A' => [...$b, ...$inA], 'missing child in A' => [...$missing, ...$inA],
    ];
}

function fillPlaceholders(mixed $value, array $values): mixed
{
    return json_decode(strtr(json_encode($value), array_map(fn ($v) => trim(json_encode($v), '"'), $values)), true);
}

/** Replaces the variant's own values with "§" so B's answer and the missing answer can be compared. */
function normalizeAnswer(string $text, array $values): string
{
    uasort($values, fn ($a, $b) => strlen($b) <=> strlen($a));
    foreach ($values as $v) {
        $text = ctype_digit($v)
            // Not next to ':' (JSON values like "per_page":25, timestamp parts like 21:54:35): an id can collide with them.
            // Skipping them only makes the check stricter.
            ? preg_replace('/(?<![\w:-])'.$v.'(?![\w:-])/', '§', $text)
            : str_ireplace([$v, json_encode($v), rawurlencode($v)], '§', $text);
    }

    return $text;
}

function workspaceSnapshot(Workspace $w): string
{
    $projects = Project::where('workspace_id', $w->id)->orderBy('id')->pluck('id');
    $issues = Issue::withTrashed()->whereIn('project_id', $projects)->orderBy('id')->get();

    return md5(json_encode([
        Project::whereIn('id', $projects)->orderBy('id')->get(), $issues,
        Comment::withTrashed()->whereIn('issue_id', $issues->pluck('id'))->orderBy('id')->get(),
        DB::table('issue_links')->whereIn('source_issue_id', $issues->pluck('id'))->orWhereIn('target_issue_id', $issues->pluck('id'))->orderBy('id')->get(),
        Label::whereIn('project_id', $projects)->orderBy('id')->get(), Column::whereIn('project_id', $projects)->orderBy('id')->get(),
        Sprint::whereIn('project_id', $projects)->orderBy('id')->get(),
        DB::table('project_user')->whereIn('project_id', $projects)->orderBy('id')->get(),
        DB::table('workspace_user')->where('workspace_id', $w->id)->orderBy('user_id')->get(),
        DB::table('workspace_invitations')->where('workspace_id', $w->id)->orderBy('id')->get(),
        DB::table('personal_access_tokens')->where('workspace_id', $w->id)->orderBy('id')->get(['id', 'name', 'abilities']),
        DB::table('agents')->where('workspace_id', $w->id)->orderBy('id')->get(),
        DB::table('approval_requests')->where('workspace_id', $w->id)->orderBy('id')->get(),
        DB::table('notifications')->where('workspace_id', $w->id)->orderBy('id')->get(),
        DB::table('ai_providers')->where('workspace_id', $w->id)->orderBy('id')->get(),
        DB::table('statuses')->where('workspace_id', $w->id)->orderBy('id')->get(),
        DB::table('column_statuses')->whereIn('column_id', Column::whereIn('project_id', $projects)->select('id'))->orderBy('column_id')->get(),
        DB::table('agent_runs')->where('workspace_id', $w->id)->orderBy('id')->get(),
        DB::table('agent_run_changes')->whereIn('run_id', DB::table('agent_runs')->where('workspace_id', $w->id)->select('id'))->orderBy('id')->get(),
    ]));
}

beforeEach(function () {
    // On, so the billing route's 404 for B means "not yours", not "billing is off".
    config(['pm.billing_url' => 'https://billing.example.test', 'pm.billing_link_secret' => 'billing-secret']);
    $this->ann = User::factory()->create(['email' => 'ann@example.com']);
    $this->bob = User::factory()->create(['email' => 'bob@example.com']);

    // Workspace B, built first so A is current afterwards.
    $this->b = makeWorkspace($this->bob, 'bravo');
    $bWeb = makeProject($this->bob, 'WEB', 'B-SECRET web');
    makeIssue($bWeb, $this->bob, ['title' => 'B-SECRET web issue']);
    $ops = makeProject($this->bob, 'OPS', 'B-SECRET ops');
    addMember($bWeb, $this->ann, ProjectRole::Admin);
    addMember($ops, $this->ann, ProjectRole::Admin);
    $opsIssue = makeIssue($ops, $this->bob, ['title' => 'B-SECRET ops issue']);
    $this->bComment = app(CommentService::class)->add($opsIssue, $this->bob, 'B-SECRET comment');
    $this->bLabel = app(LabelService::class)->create($ops, ['name' => 'b-secret']);
    $this->bColumn = app(ColumnService::class)->create($ops, $this->bob, ['name' => 'B-SECRET col']);
    $this->bStatus = Status::where('workspace_id', $this->b->id)->where('name', 'B-SECRET col')->sole();
    $this->bSprint = app(SprintService::class)->create($ops, ['name' => 'B-SECRET sprint']);
    $this->bInvitation = app(InvitationService::class)->invite($this->b, $this->bob, 'carol@example.com', WorkspaceRole::Member, $ops, ProjectRole::Member);
    $this->bNotification = app(NotificationService::class)->notify($this->ann, $opsIssue, 'commented', ['action' => 'commented', 'from' => null, 'to' => null], $this->bob, null, 'web');
    $this->annB = app(AgentService::class)->create($this->ann, ['name' => 'B-SECRET token', 'level' => 'delete']);
    AiProvider::create(['workspace_id' => $this->b->id, 'provider' => 'anthropic', 'model' => 'B-SECRET model', 'key' => 'B-SECRET-key', 'monthly_token_cap' => 7]);
    // ann's own run in B, with a proposed change: only the workspace boundary stops her.
    $this->bRun = AgentRun::create(['workspace_id' => $this->b->id, 'issue_id' => $opsIssue->id, 'issue_key' => 'OPS-1', 'user_id' => $this->ann->id, 'agent_id' => $this->annB[0]->id,
        'role_name' => 'Instruction', 'instruction' => 'B-SECRET instruction', 'status' => AgentRunStatus::Finished]);
    $this->bRunChange = AgentRunChange::create(['run_id' => $this->bRun->id, 'kind' => 'field', 'target' => 'title', 'before' => ['value' => 'B-SECRET ops issue'],
        'after' => ['value' => 'B-SECRET new title'], 'issue_version' => $opsIssue->fresh()->version, 'status' => AgentRunChangeStatus::Proposed]);
    $this->bApproval = app(ApprovalService::class)->requestDone($opsIssue->setRelation('project', $ops), $this->ann, $this->annB[0], app(StatusService::class)->resolveFor($ops, 'Done'), 'B-SECRET reason');

    // Workspace A: ann's current workspace.
    $this->a = makeWorkspace($this->ann, 'alpha');
    $web = makeProject($this->ann, 'WEB', 'Alpha web');
    $issue = makeIssue($web, $this->ann, ['title' => 'Alpha issue']);
    app(CommentService::class)->add($issue, $this->ann, 'Alpha comment');
    app(LabelService::class)->create($web, ['name' => 'alpha']);
    app(SprintService::class)->create($web, ['name' => 'Alpha sprint']);
    $this->annA = app(AgentService::class)->create($this->ann, ['name' => 'ann in A', 'level' => 'delete']);
    $this->aRun = AgentRun::create(['workspace_id' => $this->a->id, 'issue_id' => $issue->id, 'issue_key' => 'WEB-1', 'user_id' => $this->ann->id, 'agent_id' => $this->annA[0]->id,
        'role_name' => 'Instruction', 'status' => AgentRunStatus::Finished]);

    $this->bBefore = workspaceSnapshot($this->b);
});

function answerRoute($t, string $route, array $values): string
{
    [$path, $payload] = isolationRouteCases()[$route];
    $method = explode(' ', $route)[0];
    $uri = '/'.fillPlaceholders($path, $values);
    $payload = fillPlaceholders($payload, $values);

    if (str_starts_with($path, 'api/')) {
        $response = $t->withToken($t->annA[1])->json($method, $uri, $payload);
        // Debug traces carry line numbers, which can collide with ids during normalizing.
        $json = json_decode($response->getContent(), true);
        $answer = is_array($json) ? json_encode(Arr::except($json, ['exception', 'file', 'line', 'trace'])) : $response->getContent();
    } else {
        $response = $t->actingAs($t->ann)->from('/dashboard')->call($method, $uri, $payload);
        $errors = session('errors');
        $errors = $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : $errors;
        // Web errors are a status page or a redirect with session errors; the 404 page itself is static HTML.
        $answer = $response->headers->get('Location').' '.json_encode($errors);
        // The CSV export streams its body.
        $content = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : $response->getContent();
        expect($content)->not->toContain(B_MARKER);
        $t->flushSession();
    }

    // The status stays out of normalizing: an id can be 404 too.
    return $response->getStatusCode().' '.normalizeAnswer($answer, $values);
}

function answerTool($t, string $tool, array $values): string
{
    $args = fillPlaceholders(collect(isolationToolCases()[$tool])->except('_compare')->all(), $values);
    Auth::setUser($t->ann->fresh()->withAccessToken($t->annA[0]->token()->sole()));
    app(CurrentWorkspace::class)->set($t->a);
    $response = PmServer::tool($tool, $args);
    $text = (fn () => json_encode([...$this->content(), ...$this->errors()]))->call($response);

    return normalizeAnswer($text, $values);
}

test('every route and MCP tool has an isolation case or a reasoned exemption', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => "{$m} {$r->uri()}"))
        ->unique();
    $tools = (new ReflectionClass(PmServer::class))->getProperty('tools')->getDefaultValue();

    expect($routes->diff([...array_keys(isolationRouteCases()), ...array_keys(isolationExemptRoutes())])->values()->all())->toBe([])
        ->and(collect($tools)->diff(array_keys(isolationToolCases()))->values()->all())->toBe([]);
});

test('acting in A, a route never shows or changes B and answers B like something missing', function (string $route) {
    $case = isolationRouteCases()[$route];
    $hasChild = collect(CHILD_PLACEHOLDERS)->contains(fn ($p) => str_contains(json_encode($case), $p));
    $answers = collect(isolationVariants($this))
        ->filter(fn ($v, $name) => $hasChild || ! str_contains($name, 'child'))
        ->map(fn ($values) => answerRoute($this, $route, $values));

    foreach ($answers as $answer) {
        expect($answer)->not->toContain(B_MARKER);
    }
    if ($case[2] ?? true) {
        expect($answers['b'])->toBe($answers['missing']);
        if ($hasChild) {
            expect($answers['b child in A'])->toBe($answers['missing child in A']);
        }
    }
    expect(workspaceSnapshot($this->b))->toBe($this->bBefore);
})->with(fn () => array_keys(isolationRouteCases()));

test('acting in A, an MCP tool never shows or changes B and answers B like something missing', function (string $tool) {
    $case = isolationToolCases()[$tool];
    $hasChild = collect(CHILD_PLACEHOLDERS)->contains(fn ($p) => str_contains(json_encode($case), $p));
    $answers = collect(isolationVariants($this))
        ->filter(fn ($v, $name) => $hasChild || ! str_contains($name, 'child'))
        ->map(fn ($values) => answerTool($this, $tool, $values));

    foreach ($answers as $answer) {
        expect($answer)->not->toContain(B_MARKER);
    }
    if ($case['_compare'] ?? true) {
        expect($answers['b'])->toBe($answers['missing']);
        if ($hasChild) {
            expect($answers['b child in A'])->toBe($answers['missing child in A']);
        }
    }
    expect(workspaceSnapshot($this->b))->toBe($this->bBefore);
})->with(fn () => array_keys(isolationToolCases()));

test('the same issue key means the current workspace\'s issue', function () {
    $this->withToken($this->annA[1])->getJson('/api/v1/issues/WEB-1')->assertOk()->assertJsonPath('data.title', 'Alpha issue');
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->withToken($this->annB[1])->getJson('/api/v1/issues/WEB-1')->assertOk()->assertJsonPath('data.title', 'B-SECRET web issue');
});

test('an agent cannot be scoped to another workspace\'s project', function () {
    useWorkspace($this->a, $this->ann);

    expect(fn () => app(AgentService::class)->create($this->ann, ['name' => 'x', 'level' => 'write', 'projects' => ['OPS']]))
        ->toThrow(ValidationException::class, 'You are not a member of: OPS. Your projects: WEB.');
});

test('an agent whose owner left its workspace is revoked and its token gets 401, on the API, MCP over HTTP and stdio', function () {
    [$mcp, $mcpPlain] = app(AgentService::class)->create($this->ann, ['name' => 'mcp', 'level' => 'write']);  // workspace A
    [$stdio, $stdioPlain] = app(AgentService::class)->create($this->ann, ['name' => 'stdio', 'level' => 'write']);
    $this->a->members()->detach($this->ann);

    $this->withToken($this->annA[1])->getJson('/api/v1/projects')->assertUnauthorized();
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->withToken($mcpPlain)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    expect(fn () => LocalPmServer::authenticate($stdioPlain))->toThrow(RuntimeException::class);

    expect(PersonalAccessToken::whereIn('agent_id', [$this->annA[0]->id, $mcp->id, $stdio->id])->count())->toBe(0)
        ->and(Agent::whereKey([$this->annA[0]->id, $mcp->id, $stdio->id])->pluck('status')->unique()->all())->toBe([AgentStatus::Revoked])
        ->and(PersonalAccessToken::where('agent_id', $this->annB[0]->id)->exists())->toBeTrue()
        ->and($this->annB[0]->fresh()->status)->toBe(AgentStatus::Active);
});

test('the agent list of a workspace never shows another workspace\'s agents, even to its Owner', function () {
    expect(collect(app(AgentService::class)->listFor($this->ann, $this->a))->pluck('name')->all())->toBe(['ann in A'])
        ->and(collect(app(AgentService::class)->listFor($this->bob, $this->b))->pluck('name')->all())->toBe(['B-SECRET token']);
});

test('OAuth: the consent screen in A offers only A\'s agents, refuses B\'s, and the token never sees B', function () {
    useWorkspace($this->a, $this->ann);
    $clientId = oauthClient();
    $url = oauthAuthorizeUrl($clientId, pkce()[1]);
    $page = $this->actingAs($this->ann)->get($url)->assertOk()->viewData('page')['props'];

    expect(collect($page['agents'])->pluck('name')->all())->toBe(['ann in A'])->and(json_encode($page))->not->toContain(B_MARKER);
    $this->from($url)->post('/oauth/authorize', ['auth_token' => $page['authToken'], 'agent' => $this->annB[0]->id])->assertSessionHasErrors('agent');

    $tokens = oauthConnect($this->ann, $this->annA[0]->id);
    $this->ann->forceFill(['current_workspace_id' => $this->b->id])->save();
    expect(json_encode(mcpJson($tokens['access_token'], 'list_projects')))->not->toContain(B_MARKER)->toContain('Alpha web')
        ->and(json_encode(mcpJson($tokens['access_token'], 'search_issues', ['q' => 'secret'])))->not->toContain(B_MARKER);
    asToken($tokens['access_token'])->getJson('/api/v1/projects')->assertUnauthorized();
    expect(workspaceSnapshot($this->b))->toBe($this->bBefore);
});

test('OAuth: a consent screen shown in A is never approved in B, even after switching to B', function () {
    useWorkspace($this->a, $this->ann);
    $url = oauthAuthorizeUrl(oauthClient(), pkce()[1]);
    $authToken = $this->actingAs($this->ann)->get($url)->viewData('page')['props']['authToken'];
    $this->ann->forceFill(['current_workspace_id' => $this->b->id])->save();

    $this->from($url)->post('/oauth/authorize', ['auth_token' => $authToken, 'agent' => $this->annB[0]->id])
        ->assertSessionHasErrors(['agent' => 'You switched to Bravo after this screen opened. Choose an agent again.']);
    expect(AuthCode::count())->toBe(0)->and(workspaceSnapshot($this->b))->toBe($this->bBefore);
});

test('live channels: acting in A never subscribes to B\'s projects, and a user channel is only its user\'s', function () {
    useReverbForChannelAuth();
    $ops = Project::where('workspace_id', $this->b->id)->where('key', 'OPS')->sole();
    $web = Project::where('workspace_id', $this->a->id)->sole();

    // B's project named under A (ann is in both, and Admin of OPS): refused like a project that doesn't exist.
    channelAuth($this->ann, "workspace.{$this->a->id}.project.{$ops->id}")->assertForbidden();
    channelAuth($this->ann, "workspace.{$this->a->id}.project.999999")->assertForbidden();
    // bob is only in B.
    channelAuth($this->bob, "workspace.{$this->a->id}.project.{$web->id}")->assertForbidden();
    channelAuth($this->bob, "workspace.{$this->b->id}.project.{$web->id}")->assertForbidden();
    channelAuth($this->ann, "user.{$this->bob->id}")->assertForbidden();
    // The legitimate ones still work.
    channelAuth($this->ann, "workspace.{$this->a->id}.project.{$web->id}")->assertOk();
    channelAuth($this->bob, "workspace.{$this->b->id}.project.{$ops->id}")->assertOk();
});

test('agent runs: a run of another workspace or another person is never listed, and its live event goes to its starter only', function () {
    useReverbForChannelAuth();
    $run = fn (Workspace $ws, User $user) => AgentRun::create(['workspace_id' => $ws->id, 'issue_key' => 'WEB-1', 'user_id' => $user->id, 'role_name' => 'Triage', 'status' => AgentRunStatus::Finished]);
    $bRun = $run($this->b, $this->ann);
    $carol = User::factory()->create();
    $this->a->addMember($carol, WorkspaceRole::Admin);
    $carolsRun = $run($this->a, $carol);
    $annsRun = $run($this->a, $this->ann);

    expect(AgentRun::visibleTo($this->ann)->orderBy('id')->pluck('id')->all())->toBe([$this->aRun->id, $annsRun->id])
        ->and(AgentRun::visibleTo($carol)->pluck('id')->all())->toBe([$carolsRun->id])
        ->and(AgentRun::visibleTo($this->ann)->whereKey($bRun->id)->exists())->toBeFalse()
        ->and((new AgentRunChanged($this->ann->id, $annsRun->id, 'finished'))->broadcastOn()->name)->toBe("private-user.{$this->ann->id}");
    channelAuth($carol, "user.{$this->ann->id}")->assertForbidden();
    channelAuth($this->bob, "user.{$this->ann->id}")->assertForbidden();
});

test('the internal admin API answers a user\'s Sanctum or OAuth token, from either workspace, with a bare 401', function () {
    config(['pm.internal_api_token' => INTERNAL_TOKEN]);
    $tokens = oauthConnect($this->ann, $this->annA[0]->id);

    foreach ([$this->annA[1], $this->annB[1], $tokens['access_token']] as $token) {
        internalApi('GET', "workspaces/{$this->b->id}", token: $token)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        internalApi('POST', "workspaces/{$this->b->id}/suspend", ['reason' => 'x'], token: $token)->assertUnauthorized();
    }
    expect($this->b->fresh()->suspended_at)->toBeNull()->and(workspaceSnapshot($this->b))->toBe($this->bBefore);
});

test('the web restore of an archived workspace never reaches one you don\'t own or aren\'t in', function () {
    $this->b->forceFill(['archived_at' => now()])->save();
    $carol = User::factory()->create();
    makeWorkspace($carol, 'carol');

    // ann is a plain member of B; carol isn't in B at all.
    $this->actingAs($this->ann)->post('/workspaces/bravo/restore')->assertForbidden();
    $this->actingAs($carol)->post('/workspaces/bravo/restore')->assertNotFound();
    expect($this->b->fresh()->archived_at)->not->toBeNull();
});

test('the Billing link never reaches a workspace you are not an Owner of', function () {
    $carol = User::factory()->create();
    makeWorkspace($carol, 'carol');

    $this->actingAs($carol)->get('/bravo/settings/billing')->assertNotFound();
    // ann is a plain member of B.
    $this->actingAs($this->ann)->get('/bravo/settings/billing')->assertForbidden();
    $this->actingAs($this->bob)->get('/bravo/settings/billing')->assertRedirectContains('https://billing.example.test/session?token=');
});

test('a support session opened in A never shows B, even though its user is in B', function () {
    config(['pm.internal_api_token' => INTERNAL_TOKEN]);
    $url = internalApi('POST', "workspaces/{$this->a->id}/support-sessions", [
        'staff' => ['name' => 'Sue', 'email' => 'sue@pmops.test'], 'target_user_id' => $this->ann->id, 'reason' => 'x', 'minutes' => 5,
    ])->assertCreated()->json('data.url');
    $this->flushHeaders();

    $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect('/alpha/dashboard');
    expect($this->get('/bravo/dashboard')->assertNotFound()->getContent())->not->toContain(B_MARKER);
    expect($this->get('/bravo/projects/OPS')->assertNotFound()->getContent())->not->toContain(B_MARKER);

    // A session started for B's workspace can't be started for a user who isn't in it.
    internalApi('POST', "workspaces/{$this->b->id}/support-sessions", [
        'staff' => ['name' => 'Sue', 'email' => 'sue@pmops.test'], 'target_user_id' => User::factory()->create()->id, 'reason' => 'x', 'minutes' => 5,
    ])->assertUnprocessable();
});
