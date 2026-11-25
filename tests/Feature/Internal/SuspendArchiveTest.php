<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Mail\ProjectInvitationMail;
use App\Mcp\Servers\LocalPmServer;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\CreateIssue;
use App\Mcp\Tools\GetIssue;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

// Review Focus 4: a suspended workspace is read-only on every path (3-R16); an archived one shows its page (3-R17).

const SUSPENDED = 'This workspace is suspended. You can view and export, but not make changes.';

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Olga Owner']);
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->project = makeProject($this->owner, 'WEB');
    makeIssue($this->project, $this->owner, ['title' => 'First']);
    [$this->agent, $this->plain] = makeAgent($this->owner, AgentLevel::Write);
});

function suspendWorkspace($t): void
{
    $t->ws->forceFill(['suspended_at' => now()])->save();
    $t->snapshot = [Issue::count(), Comment::count(), Issue::first()->only(['title', 'status_id', 'rank', 'version']), $t->ws->fresh()->name];
}

/** Nothing was written, and the web answer was the notice. */
function assertRefusedOnWeb($t, $response): void
{
    $response->assertRedirect()->assertSessionHas('warning', SUSPENDED);
    expect([Issue::count(), Comment::count(), Issue::first()->only(['title', 'status_id', 'rank', 'version']), $t->ws->fresh()->name])->toBe($t->snapshot);
}

function mcpToolCall(string $token, string $tool, array $arguments = [])
{
    return asToken($token, ['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);
}

test('web: creating an issue is refused with the notice', function () {
    suspendWorkspace($this);
    assertRefusedOnWeb($this, $this->actingAs($this->owner)->post(w('/projects/WEB/issues'), ['title' => 'New']));
});

test('web: editing an issue is refused', function () {
    suspendWorkspace($this);
    assertRefusedOnWeb($this, $this->actingAs($this->owner)->patch(w('/issues/WEB-1'), ['title' => 'Renamed', 'version' => 1]));
});

test('web: a board drag is refused', function () {
    suspendWorkspace($this);
    assertRefusedOnWeb($this, $this->actingAs($this->owner)->post(w('/issues/WEB-1/move'), ['status' => 'Done', 'position' => 0]));
});

test('web: commenting is refused', function () {
    suspendWorkspace($this);
    assertRefusedOnWeb($this, $this->actingAs($this->owner)->post(w('/issues/WEB-1/comments'), ['body' => 'Hi']));
});

test('web: workspace settings are refused', function () {
    suspendWorkspace($this);
    assertRefusedOnWeb($this, $this->actingAs($this->owner)->patch(w('/settings/general'), ['name' => 'Changed', 'slug' => 'acme', 'members_can_create_projects' => true]));
});

test('web: a JSON request gets the 403 body instead of the notice', function () {
    suspendWorkspace($this);
    $this->actingAs($this->owner)->postJson(w('/projects/WEB/issues'), ['title' => 'New'])
        ->assertForbidden()->assertExactJson(['error' => 'workspace_suspended', 'message' => SUSPENDED]);
});

test('web: Undo is refused', function () {
    $this->actingAs($this->owner)->post(w('/issues/WEB-1/move'), ['status' => 'Done', 'position' => 0])->assertSessionHasNoErrors();
    $undo = session('undo');
    suspendWorkspace($this);

    assertRefusedOnWeb($this, $this->{$undo['method']}($undo['url'], $undo['data']));
});

test('web: an approval decision is refused and the request stays pending', function () {
    asToken($this->plain)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertStatus(202);
    $id = ApprovalRequest::sole()->id;
    suspendWorkspace($this);
    $this->flushHeaders();
    app('auth')->forgetGuards();

    assertRefusedOnWeb($this, $this->actingAs($this->owner)->post(w("/approvals/{$id}/approve")));
    assertRefusedOnWeb($this, $this->post(w("/approvals/{$id}/reject"), ['note' => 'no']));
    expect(ApprovalRequest::sole()->status->value)->toBe('pending');
});

test('REST: a write is 403 workspace_suspended', function () {
    suspendWorkspace($this);
    asToken($this->plain)->postJson('/api/v1/projects/WEB/issues', ['title' => 'New'])
        ->assertForbidden()->assertExactJson(['error' => 'workspace_suspended', 'message' => SUSPENDED]);
    asToken($this->plain)->deleteJson('/api/v1/issues/WEB-1', ['confirm' => true])->assertForbidden()->assertJsonPath('error', 'workspace_suspended');
    expect(Issue::count())->toBe(1);
});

test('REST: a bulk update is refused', function () {
    suspendWorkspace($this);
    asToken($this->plain)->patchJson('/api/v1/issues/bulk', ['updates' => [['key' => 'WEB-1', 'title' => 'x']]])
        ->assertForbidden()->assertJsonPath('error', 'workspace_suspended');
    expect(Issue::first()->title)->toBe('First');
});

test('MCP over HTTP: a write tool is refused, a read tool works', function () {
    suspendWorkspace($this);
    // A JSON-RPC error (laravel/mcp answers those with HTTP 400), with the same error and message as REST's 403.
    mcpToolCall($this->plain, 'create_issue', ['project' => 'WEB', 'title' => 'New'])
        ->assertJsonPath('error.message', SUSPENDED)
        ->assertJsonPath('error.data', ['error' => 'workspace_suspended', 'message' => SUSPENDED]);
    mcpToolCall($this->plain, 'bulk_update_issues', ['updates' => [['key' => 'WEB-1', 'title' => 'x']]])->assertJsonPath('error.data.error', 'workspace_suspended');

    expect(mcpJson($this->plain, 'get_issue', ['issue' => 'WEB-1'])['title'])->toBe('First')
        ->and(Issue::count())->toBe(1)->and(Issue::first()->title)->toBe('First');
});

test('MCP over stdio: a long-lived process is refused writes once suspended, and reads still work', function () {
    LocalPmServer::authenticate($this->plain);
    PmServer::tool(CreateIssue::class, ['project' => 'WEB', 'title' => 'Before'])->assertOk();
    suspendWorkspace($this);

    PmServer::tool(CreateIssue::class, ['project' => 'WEB', 'title' => 'After'])->assertHasErrors([SUSPENDED]);
    PmServer::tool(GetIssue::class, ['issue' => 'WEB-1'])->assertOk();
    expect(Issue::where('title', 'After')->exists())->toBeFalse();
});

test('reads, export, sign-out and switching still work, and the banner state is shared', function () {
    $other = makeWorkspace($this->owner, 'other');
    useWorkspace($this->ws, $this->owner);
    suspendWorkspace($this);

    $this->actingAs($this->owner)->get(w('/projects/WEB'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('workspace.state', 'suspended'));
    $this->get(w('/issues/WEB-1'))->assertOk();
    $this->get(w('/settings/audit-log/export'))->assertOk();
    $this->get('/other/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.state', 'active'));
    asToken($this->plain)->getJson('/api/v1/issues/WEB-1')->assertOk();

    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($this->owner, 'web')->post('/logout')->assertRedirect('/');
    $this->assertGuest('web');
});

test('marking your own notifications read still works while suspended', function () {
    suspendWorkspace($this);
    $this->actingAs($this->owner)->post(w('/notifications/read-all'))->assertRedirect()->assertSessionMissing('warning');
});

test('archived: members see the archived page; an Owner restores it, a Member cannot', function () {
    $member = User::factory()->create();
    addMember($this->project, $member, ProjectRole::Member);
    $this->ws->forceFill(['archived_at' => now()])->save();

    $this->actingAs($member)->get(w('/dashboard'))->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Workspaces/Archived')->where('restoreUrl', null)->where('name', 'Acme'));
    $this->post('/workspaces/acme/restore')->assertForbidden();
    expect($this->ws->fresh()->archived_at)->not->toBeNull();

    $this->actingAs($this->owner)->get(w('/projects/WEB'))->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Workspaces/Archived')->where('restoreUrl', route('workspaces.restore', 'acme')));
    $this->post('/workspaces/acme/restore')->assertRedirect('/acme/dashboard');

    expect($this->ws->fresh()->archived_at)->toBeNull()
        ->and(AuditLog::where('action', 'workspace.restored')->sole()->only(['actor_type', 'actor_user_id']))->toBe(['actor_type' => 'user', 'actor_user_id' => $this->owner->id]);
    $this->get(w('/projects/WEB'))->assertOk();
});

test('archived: REST, MCP over HTTP and stdio get 403 workspace_archived', function () {
    $this->ws->forceFill(['archived_at' => now()])->save();
    $archived = ['error' => 'workspace_archived', 'message' => 'This workspace was archived after 12 months without activity. An Owner can restore it in the web app.'];

    asToken($this->plain)->getJson('/api/v1/projects')->assertForbidden()->assertExactJson($archived);
    mcpToolCall($this->plain, 'list_projects')->assertForbidden()->assertExactJson($archived);
    expect(fn () => LocalPmServer::authenticate($this->plain))->toThrow(RuntimeException::class, $archived['message']);
});

test('archived while a stdio process runs: its next call is refused', function () {
    LocalPmServer::authenticate($this->plain);
    PmServer::tool(GetIssue::class, ['issue' => 'WEB-1'])->assertOk();
    $this->ws->forceFill(['archived_at' => now()])->save();

    PmServer::tool(GetIssue::class, ['issue' => 'WEB-1'])->assertHasErrors(['This workspace was archived after 12 months without activity.']);
});

test('a Viewer-only member of a suspended workspace still reads', function () {
    $viewer = User::factory()->create();
    addMember($this->project, $viewer, ProjectRole::Viewer);
    $this->ws->members()->updateExistingPivot($viewer->id, ['role' => WorkspaceRole::Member->value]);
    suspendWorkspace($this);

    $this->actingAs($viewer)->get(w('/projects/WEB'))->assertOk();
});

test('connecting an MCP client is refused while suspended: consent would create an agent', function () {
    $client = oauthClient();
    suspendWorkspace($this);

    $this->actingAs($this->owner, 'web')->get(oauthAuthorizeUrl($client, pkce()[1]))->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Workspaces/NoAccess')->where('message', SUSPENDED));
});

test('web: a backlog rank is refused', function () {
    makeIssue($this->project, $this->owner, ['title' => 'Second']);
    suspendWorkspace($this);
    assertRefusedOnWeb($this, $this->actingAs($this->owner, 'web')->post(w('/issues/WEB-1/rank'), ['after' => 'WEB-2']));
});

/** A pending invitation of carol to WEB and its plain token, taken from the email. */
function invitationToken($t): string
{
    Mail::fake();
    app(InvitationService::class)->invite($t->ws, $t->owner, 'carol@example.com', WorkspaceRole::Member, $t->project, ProjectRole::Member);
    $token = null;
    Mail::assertSent(ProjectInvitationMail::class, function (ProjectInvitationMail $mail) use (&$token) {
        $token = $mail->token;

        return true;
    });

    return $token;
}

test('joining by the emailed link is refused in a suspended or archived workspace', function (string $column, string $message) {
    $token = invitationToken($this);
    $this->ws->forceFill([$column => now()])->save();
    $carol = User::factory()->create(['email' => 'carol@example.com']);

    $this->actingAs($carol, 'web')->post("/invitations/{$token}")->assertSessionHasErrors(['invitation' => $message]);
    expect($carol->workspaceRole($this->ws))->toBeNull();
})->with([
    'suspended' => ['suspended_at', SUSPENDED],
    'archived' => ['archived_at', 'This workspace was archived after 12 months without activity. An Owner can restore it in the web app.'],
]);

test('accepting from the pending list is refused in a suspended or archived workspace', function (string $column, string $message) {
    invitationToken($this);
    $invitation = WorkspaceInvitation::sole();
    $this->ws->forceFill([$column => now()])->save();
    $carol = User::factory()->create(['email' => 'carol@example.com']);

    $this->actingAs($carol, 'web')->post("/invitations/{$invitation->id}/accept")->assertSessionHasErrors(['invitation' => $message]);
    expect($carol->workspaceRole($this->ws))->toBeNull()->and(WorkspaceInvitation::count())->toBe(1);
})->with([
    'suspended' => ['suspended_at', SUSPENDED],
    'archived' => ['archived_at', 'This workspace was archived after 12 months without activity. An Owner can restore it in the web app.'],
]);
