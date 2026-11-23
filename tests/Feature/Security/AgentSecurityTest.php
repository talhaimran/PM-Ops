<?php

use App\Enums\AgentLevel;
use App\Enums\AgentStatus;
use App\Enums\Entitlement;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Mcp\Servers\LocalPmServer;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteProject;
use App\Mcp\Tools\ListProjects;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\AgentService;
use App\Services\AuditLogger;
use App\Services\ColumnService;
use App\Services\CommentService;
use App\Services\Entitlements;
use App\Services\LabelService;
use App\Services\ProjectService;
use App\Services\SprintService;
use App\Services\WorkspaceMemberService;
use App\Services\WorkspaceService;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * Step A1: an agent sees at most what its owner sees (owner's project role, then agent level, then the agent's
 * project list), and pausing or revoking it takes effect on its next call, never touching the owner.
 */

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Talha Imran']);
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->web = makeProject($this->owner, 'WEB');
    $this->api = makeProject($this->owner, 'API');
    makeIssue($this->web, $this->owner, ['title' => 'First']);
    $this->agents = app(AgentService::class);
});

function mcpToolsList(string $token)
{
    return asToken($token)->withHeader('Accept', 'application/json, text/event-stream')
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []]);
}

test('a Read agent of a project Admin cannot write', function () {
    [, $plain] = makeAgent($this->owner, AgentLevel::Read);

    asToken($plain)->postJson('/api/v1/projects/WEB/issues', ['title' => 'x'])
        ->assertForbidden()
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'read-only'));
});

test('a Write agent of a project Viewer cannot write: the owner\'s role comes first', function () {
    $viewer = addMember($this->web, User::factory()->create(), ProjectRole::Viewer);
    [, $plain] = makeAgent($viewer, AgentLevel::Write);

    asToken($plain)->postJson('/api/v1/projects/WEB/issues', ['title' => 'x'])
        ->assertForbidden()
        ->assertJsonPath('message', "Your role in WEB is viewer; viewers can't make changes.");
});

test('a Write agent scoped to API gets a 404 for WEB listing the projects it can reach', function () {
    [, $plain] = makeAgent($this->owner, AgentLevel::Write, ['API']);

    asToken($plain)->postJson('/api/v1/projects/WEB/issues', ['title' => 'x'])
        ->assertNotFound()
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Projects you can access: API.'));
});

test('deleting needs the Delete level', function () {
    [, $write] = makeAgent($this->owner, AgentLevel::Write);
    [, $delete] = makeAgent($this->owner, AgentLevel::Delete);

    asToken($write)->deleteJson('/api/v1/issues/WEB-1', ['confirm' => true])
        ->assertForbidden()
        ->assertJsonPath('message', "This agent can't delete. Its owner can allow deletes in Settings → Agents.");
    asToken($delete)->deleteJson('/api/v1/issues/WEB-1', ['confirm' => true])->assertNoContent();
});

test('a paused agent gets agent_paused on REST and MCP over HTTP; its owner keeps working', function () {
    [$agent, $plain] = makeAgent($this->owner, AgentLevel::Write, name: 'deploy-bot');
    $this->agents->pause($agent, $this->owner);
    $paused = ['error' => 'agent_paused', 'message' => 'deploy-bot is paused. Its owner or a workspace Admin can resume it in Settings → Agents.'];

    asToken($plain)->getJson('/api/v1/projects')->assertForbidden()->assertExactJson($paused);
    mcpToolsList($plain)->assertForbidden()->assertExactJson($paused);

    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($this->owner)->get(w('/dashboard'))->assertOk();

    $this->agents->resume($agent, $this->owner);
    asToken($plain)->getJson('/api/v1/projects')->assertOk();
});

test('a revoked agent gets 401; its token row is gone and the agent row is kept as revoked', function () {
    [$agent, $plain] = makeAgent($this->owner, AgentLevel::Write);
    $this->agents->revoke($agent, $this->owner);

    asToken($plain)->getJson('/api/v1/projects')->assertUnauthorized();
    mcpToolsList($plain)->assertUnauthorized();
    expect(PersonalAccessToken::where('agent_id', $agent->id)->exists())->toBeFalse()
        ->and($agent->fresh()->status)->toBe(AgentStatus::Revoked);
});

test('removing the owner from the workspace revokes their agents as the system', function () {
    $alex = addMember($this->web, User::factory()->create(), ProjectRole::Member);
    [$agent, $plain] = makeAgent($alex, AgentLevel::Write);

    app(WorkspaceMemberService::class)->remove($this->ws, $this->owner, $alex);

    expect($agent->fresh()->status)->toBe(AgentStatus::Revoked)
        ->and(PersonalAccessToken::where('agent_id', $agent->id)->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'agent.revoked')->sole()->only(['actor_type', 'actor_user_id', 'metadata']))
        ->toEqual(['actor_type' => 'system', 'actor_user_id' => null, 'metadata' => ['by' => 'system', 'reason' => 'owner_left']]);
    asToken($plain)->getJson('/api/v1/projects')->assertUnauthorized();
});

test('a long-lived stdio server stops at the next tool call once its agent is paused or revoked', function () {
    [$agent, $plain] = makeAgent($this->owner, AgentLevel::Write, name: 'stdio-bot');
    LocalPmServer::authenticate($plain);

    PmServer::tool(ListProjects::class)->assertOk();

    $this->agents->pause($agent, $this->owner);
    PmServer::tool(ListProjects::class)->assertHasErrors(['stdio-bot is paused.']);

    $this->agents->resume($agent, $this->owner);
    PmServer::tool(ListProjects::class)->assertOk();

    $this->agents->revoke($agent, $this->owner);
    PmServer::tool(ListProjects::class)->assertHasErrors(['stdio-bot was revoked.']);
});

test('a long-lived stdio server stops at the next tool call once its token is regenerated or expires', function () {
    [$agent, $plain] = makeAgent($this->owner, AgentLevel::Write);
    LocalPmServer::authenticate($plain);
    PmServer::tool(ListProjects::class)->assertOk();

    $this->agents->regenerateToken($agent, $this->owner);
    PmServer::tool(ListProjects::class)->assertHasErrors(['PM_MCP_TOKEN is missing or invalid.']);

    [, $expiring] = $this->agents->create($this->owner, ['name' => 'short-lived', 'level' => 'write', 'expires_in_days' => 1]);
    LocalPmServer::authenticate($expiring);
    PmServer::tool(ListProjects::class)->assertOk();

    $this->travel(2)->days();
    PmServer::tool(ListProjects::class)->assertHasErrors(['PM_MCP_TOKEN is missing or invalid.']);
});

test('the rate limit is per agent, and regenerating the token does not reset it', function () {
    app(Entitlements::class)->set($this->ws, Entitlement::ApiPerMinute, '2', 'override');
    [$agent, $a] = makeAgent($this->owner, AgentLevel::Read);
    [, $b] = makeAgent($this->owner, AgentLevel::Read);

    asToken($a)->getJson('/api/v1/projects')->assertOk();
    asToken($a)->getJson('/api/v1/projects')->assertOk();
    asToken($a)->getJson('/api/v1/projects')->assertStatus(429)->assertHeader('Retry-After')
        ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Too many requests: the limit is 2 per minute per agent.'));
    asToken($b)->getJson('/api/v1/projects')->assertOk();

    $new = $this->agents->regenerateToken($agent, $this->owner);
    asToken($new)->getJson('/api/v1/projects')->assertStatus(429);
});

test('activity, comments and audit rows written by an agent carry its id; activity keeps the token name', function () {
    [$agent, $plain] = makeAgent($this->owner, AgentLevel::Write, name: 'claude-code');

    asToken($plain)->postJson('/api/v1/projects/WEB/issues', ['title' => 'By agent'])->assertCreated();
    asToken($plain)->postJson('/api/v1/issues/WEB-2/comments', ['body' => 'Done'])->assertCreated();
    // Agents can't create projects on REST any more (A-D2); the audit row still records an agent acting in a service.
    app(AuditLogger::class)->log('project.created', null, [], $this->ws, (clone $this->owner)->withAccessToken($agent->token()->sole()));

    $activity = Activity::where('action', 'created')->latest('id')->first();
    expect($activity->agent_id)->toBe($agent->id)
        ->and($activity->token_name)->toBe('claude-code')
        ->and($activity->via)->toBe('api')
        ->and(Comment::sole()->agent_id)->toBe($agent->id)
        ->and(AuditLog::where('action', 'project.created')->latest('id')->first()->agent_id)->toBe($agent->id)
        ->and(AuditLog::where('action', 'agent.created')->latest('id')->first()->agent_id)->toBeNull();
});

test('an agent token cannot use the web UI', function () {
    [, $plain] = makeAgent($this->owner, AgentLevel::Delete);

    asToken($plain)->get('/acme/dashboard')->assertRedirect(route('login'));
});

test('a token with no agent is refused', function () {
    $plain = $this->owner->createToken('stray', ['*'])->plainTextToken;

    asToken($plain)->getJson('/api/v1/projects')->assertUnauthorized();
    expect(fn () => LocalPmServer::authenticate($plain))->toThrow(RuntimeException::class);
});

test('deleting the only project of a scoped agent revokes it rather than widening it to every project', function () {
    [$agent, $plain] = makeAgent($this->owner, AgentLevel::Write, ['API']);

    app(ProjectService::class)->delete($this->api);

    expect($agent->fresh()->status)->toBe(AgentStatus::Revoked)
        ->and(AuditLog::where('action', 'agent.revoked')->sole()->metadata)->toEqual(['by' => 'system', 'reason' => 'projects_deleted']);
    asToken($plain)->getJson('/api/v1/projects')->assertUnauthorized();
    expect(Agent::count())->toBe(1);
});

/** Scan F1: every delete needs the Delete level, not only issues. One of each deletable thing in WEB, plus project API. */
function deletables($t): array
{
    $issue = Issue::firstOrFail();
    $comment = app(CommentService::class)->add($issue, $t->owner, 'Note');
    app(LabelService::class)->create($t->web, ['name' => 'ux']);
    app(ColumnService::class)->create($t->web, $t->owner, ['name' => 'Blocked']);
    $sprint = app(SprintService::class)->create($t->web, ['name' => 'Sprint 9']);

    return [
        'project' => ['/api/v1/projects/API', fn () => Project::where('key', 'API')->exists()],
        'comment' => ["/api/v1/comments/{$comment->id}", fn () => Comment::whereKey($comment->id)->exists()],
        'label' => ['/api/v1/projects/WEB/labels/ux', fn () => $t->web->labels()->where('name', 'ux')->exists()],
        'column' => ['/api/v1/projects/WEB/columns/Blocked', fn () => $t->web->columns()->where('name', 'Blocked')->exists()],
        'sprint' => ["/api/v1/projects/WEB/sprints/{$sprint->id}", fn () => $t->web->sprints()->whereKey($sprint->id)->exists()],
    ];
}

test('a Write agent cannot delete a comment, label or sprint over REST; a Delete agent can; projects and columns are people-only', function () {
    $refused = "This agent can't delete. Its owner can allow deletes in Settings → Agents.";
    $things = deletables($this);
    [, $write] = makeAgent($this->owner, AgentLevel::Write);
    [, $delete] = makeAgent($this->owner, AgentLevel::Delete);
    $settings = ['project', 'column'];

    foreach ($things as $name => [$path, $exists]) {
        asToken($write)->deleteJson($path, ['confirm' => true])->assertForbidden()
            ->assertJsonPath('message', in_array($name, $settings, true) ? ProjectPolicy::PEOPLE_ONLY : $refused);
        expect($exists())->toBeTrue("{$name} survives a Write agent");
    }
    foreach ($things as $name => [$path, $exists]) {
        $response = asToken($delete)->deleteJson($path, ['confirm' => true]);
        in_array($name, $settings, true) ? $response->assertForbidden()->assertJsonPath('message', ProjectPolicy::PEOPLE_ONLY) : $response->assertSuccessful();
        expect($exists())->toBe(in_array($name, $settings, true), "{$name}: only people delete projects and columns");
    }
});

test('a Write agent cannot delete over MCP either; a Delete agent can', function () {
    $refused = "This agent can't delete. Its owner can allow deletes in Settings → Agents.";
    app(LabelService::class)->create($this->web, ['name' => 'ux']);

    apiAs($this->owner, AgentLevel::Write);
    PmServer::tool(DeleteProject::class, ['project' => 'API', 'confirm' => true])->assertHasErrors([ProjectPolicy::PEOPLE_ONLY]);
    PmServer::tool(DeleteLabel::class, ['project' => 'WEB', 'label' => 'ux'])->assertHasErrors([$refused]);
    expect(Project::where('key', 'API')->exists())->toBeTrue()
        ->and($this->web->labels()->where('name', 'ux')->exists())->toBeTrue();

    app('auth')->forgetGuards();
    apiAs($this->owner, AgentLevel::Delete);
    PmServer::tool(DeleteProject::class, ['project' => 'API', 'confirm' => true])->assertHasErrors([ProjectPolicy::PEOPLE_ONLY]);
    PmServer::tool(DeleteLabel::class, ['project' => 'WEB', 'label' => 'ux'])->assertOk();
});

test('people deleting in the web app are unaffected by agent levels', function () {
    $label = app(LabelService::class)->create($this->web, ['name' => 'ux']);
    $sprint = app(SprintService::class)->create($this->web, ['name' => 'Sprint 9']);

    $this->actingAs($this->owner)->delete(w("/projects/WEB/labels/{$label->id}"))->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($this->owner)->delete(w("/projects/WEB/sprints/{$sprint->id}"))->assertSessionHasNoErrors()->assertRedirect();
    expect($this->web->labels()->count())->toBe(0)->and($this->web->sprints()->count())->toBe(0);
});

test('scan F2: an agent scoped to two projects stays active after the first is deleted and is revoked after the last', function () {
    [$agent] = makeAgent($this->owner, AgentLevel::Write, ['WEB', 'API']);

    app(ProjectService::class)->delete($this->web);
    expect($agent->fresh()->status)->toBe(AgentStatus::Active);

    app(ProjectService::class)->delete($this->api->fresh());
    expect($agent->fresh()->status)->toBe(AgentStatus::Revoked);
});

test('final review: an agent edits only its own comments; a person only their own hand-typed ones', function () {
    $issue = Issue::firstOrFail();
    $typed = app(CommentService::class)->add($issue, $this->owner, 'Typed by Talha');
    [, $a] = makeAgent($this->owner, AgentLevel::Write, name: 'agent-a');
    [, $b] = makeAgent($this->owner, AgentLevel::Write, name: 'agent-b');
    $byA = asToken($a)->postJson('/api/v1/issues/WEB-1/comments', ['body' => 'From A'])->assertCreated()->json('data.id');

    // REST: the owner's Write agent can't rewrite the owner's own words, nor a sibling agent's.
    asToken($a)->patchJson("/api/v1/comments/{$typed->id}", ['body' => 'forged'])->assertForbidden();
    asToken($b)->patchJson("/api/v1/comments/{$byA}", ['body' => 'forged'])->assertForbidden();
    // MCP over HTTP: same.
    asToken($a, ['Accept' => 'application/json, text/event-stream'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'update_comment', 'arguments' => ['comment_id' => $typed->id, 'body' => 'forged']]])->assertOk()->assertJsonPath('result.isError', true);
    expect($typed->fresh()->body)->toBe('Typed by Talha')->and(Comment::find($byA)->body)->toBe('From A');

    // An agent edits its own.
    asToken($a)->patchJson("/api/v1/comments/{$byA}", ['body' => 'A again'])->assertOk();

    // On the web the person edits their typed comment, not their agent's.
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($this->owner)->get(w('/issues/WEB-1'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('issue.comments.0.body', 'Typed by Talha')->where('issue.comments.0.can_edit', true)
        ->where('issue.comments.1.body', 'A again')->where('issue.comments.1.can_edit', false));
    $this->patchJson(w("/comments/{$byA}"), ['body' => 'person on agent'])->assertForbidden();
    $this->patchJson(w("/comments/{$typed->id}"), ['body' => 'Typed again'])->assertRedirect();
    expect($typed->fresh()->body)->toBe('Typed again');
});

test('final review: a migrated duplicate-named agent can still be edited when its name is unchanged', function () {
    [$one] = makeAgent($this->owner, AgentLevel::Write, name: 'twin');
    [$two] = makeAgent($this->owner, AgentLevel::Write, name: 'other');
    Agent::whereKey($two->id)->update(['name' => 'twin']);

    $this->agents->update($two->fresh(), $this->owner, ['name' => 'twin', 'description' => 'edited']);

    expect($two->fresh()->description)->toBe('edited');
    // A real rename onto a taken name is still refused.
    expect(fn () => $this->agents->update($one->fresh(), $this->owner, ['name' => 'twin ']))->not->toThrow(ValidationException::class);
    makeAgent($this->owner, AgentLevel::Write, name: 'taken');
    expect(fn () => $this->agents->update($one->fresh(), $this->owner, ['name' => 'taken']))->toThrow(ValidationException::class);
});

test('final review: a workspace scheduled for deletion stops agents on REST, MCP over HTTP and a running stdio server', function () {
    [, $plain] = makeAgent($this->owner, AgentLevel::Write);
    LocalPmServer::authenticate($plain);
    PmServer::tool(ListProjects::class)->assertOk();

    app(WorkspaceService::class)->scheduleDeletion($this->ws, $this->owner, 'acme');
    $message = app(WorkspaceService::class)->lockedMessage($this->ws->fresh(), $this->owner);

    asToken($plain)->getJson('/api/v1/projects')->assertForbidden()->assertJsonPath('message', $message);
    mcpToolsList($plain)->assertForbidden()->assertJsonPath('message', $message);
    PmServer::tool(ListProjects::class)->assertHasErrors([$message]);
});

test('final review: spec 7.0e, with require_2fa on, an existing agent of an owner without two-factor keeps working: REST, MCP, stdio and OAuth', function () {
    [$agent, $plain] = makeAgent($this->owner, AgentLevel::Write);
    $oauth = oauthConnect($this->owner, $agent->id);
    LocalPmServer::authenticate($plain);

    $this->ws->forceFill(['settings' => [...$this->ws->settings ?? [], 'require_2fa' => true]])->save();

    asToken($plain)->getJson('/api/v1/projects')->assertOk();
    mcpToolsList($plain)->assertOk();
    mcpToolsList($oauth['access_token'])->assertOk();
    PmServer::tool(ListProjects::class)->assertOk();
});

test('final review: deleting an account revokes its agents in every workspace first, leaving agent.revoked rows', function () {
    $sam = addMember($this->web, User::factory()->create(['password' => 'password']), ProjectRole::Member);
    [$one] = makeAgent($sam, AgentLevel::Write, name: 'sam-one');
    $beta = makeWorkspace($this->owner, 'beta');
    $beta->addMember($sam, WorkspaceRole::Member);
    [$two] = makeAgent($sam, AgentLevel::Write, name: 'sam-two');
    AuditLog::query()->toBase()->delete();

    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($sam)->delete('/profile', ['password' => 'password'])->assertSessionHasNoErrors();

    $rows = AuditLog::where('action', 'agent.revoked')->orderBy('target_id')->get();
    expect($rows->pluck('target_id')->all())->toBe([$one->id, $two->id])
        ->and($rows->pluck('workspace_id')->all())->toBe([$this->ws->id, $beta->id])
        ->and($rows->pluck('metadata')->all())->each->toEqual(['by' => 'system', 'reason' => 'owner_deleted'])
        ->and(User::whereKey($sam->id)->exists())->toBeFalse();
});
