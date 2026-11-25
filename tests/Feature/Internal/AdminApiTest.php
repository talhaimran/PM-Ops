<?php

use App\Enums\Entitlement;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements;
use App\Services\InvitationService;
use Illuminate\Support\Facades\DB;

// Spec 9 / platform spec 4.1: the internal admin API Platform management calls (3-R18, 3-R20).

const ADMIN_STAFF = ['name' => 'Sue Staff', 'email' => 'sue@pmops.test'];

beforeEach(function () {
    config(['pm.internal_api_token' => INTERNAL_TOKEN]);
    $this->owner = User::factory()->create(['name' => 'Olga Owner', 'email' => 'olga@example.com']);
    $this->member = User::factory()->create(['email' => 'mem@example.com']);
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->project = makeProject($this->owner, 'WEB');
    addMember($this->project, $this->member, ProjectRole::Viewer);
});

function entitlementRow(Workspace $ws, string $key): ?array
{
    return $ws->entitlements()->where('key', $key)->first()?->only(['value', 'source', 'plan_value']);
}

test('GET workspaces/{id}: name, slug, state, Owners, entitlements and usage', function () {
    app(Entitlements::class)->set($this->ws, Entitlement::Projects, '3', 'override');

    internalApi('GET', "workspaces/{$this->ws->id}")->assertOk()
        ->assertJsonPath('data.id', $this->ws->id)
        ->assertJsonPath('data.name', 'Acme')
        ->assertJsonPath('data.slug', 'acme')
        ->assertJsonPath('data.state', 'active')
        ->assertJsonPath('data.owners', [['id' => $this->owner->id, 'name' => 'Olga Owner', 'email' => 'olga@example.com']])
        ->assertJsonPath('data.entitlements.projects', ['value' => 3, 'source' => 'override', 'plan_value' => null])
        ->assertJsonPath('data.usage.billed_users', 1)
        ->assertJsonPath('data.usage.viewers', 1)
        ->assertJsonPath('data.usage.projects', 1);
});

test('GET workspaces/{id}/usage: the 3-R20 fields', function () {
    app(InvitationService::class)->invite($this->ws, $this->owner, 'new@example.com', WorkspaceRole::Member, $this->project, ProjectRole::Member);
    [$agent] = makeAgent($this->owner);
    DB::table('workspace_api_usage')->insert([
        ['workspace_id' => $this->ws->id, 'day' => now()->subDays(3)->toDateString(), 'calls' => 5],
        ['workspace_id' => $this->ws->id, 'day' => now()->subDays(40)->toDateString(), 'calls' => 100],
    ]);
    $agent->forceFill(['last_used_at' => now()->addMinute()])->save();

    $usage = internalApi('GET', "workspaces/{$this->ws->id}/usage")->assertOk()->json('data');

    expect($usage)->toMatchArray([
        'billed_users' => 1, 'viewers' => 1, 'pending_billed_invitations' => 1, 'projects' => 1, 'storage_mb' => 0,
        'agents' => 1, 'api_calls_30d' => 5,
    ])->and($usage['last_activity_at'])->toBe($agent->fresh()->last_used_at->toIso8601String());
});

test('an API call by an agent counts once in today\'s usage row', function () {
    [, $plain] = makeAgent($this->owner);
    asToken($plain)->getJson('/api/v1/projects')->assertOk();
    asToken($plain)->getJson('/api/v1/projects')->assertOk();

    expect(DB::table('workspace_api_usage')->where('workspace_id', $this->ws->id)->where('day', now()->toDateString())->value('calls'))->toBe(2);
});

test('GET workspaces pages by cursor in id order and filters by updated_since', function () {
    $others = collect(range(1, 3))->map(fn ($n) => makeWorkspace(User::factory()->create(), "other-{$n}"));
    $first = internalApi('GET', 'workspaces?per_page=2')->assertOk()->assertJsonCount(2, 'data');
    $second = internalApi('GET', 'workspaces?cursor='.$first->json('meta.next_cursor'))->assertOk();

    expect([...$first->json('data.*.id'), ...$second->json('data.*.id')])->toBe([$this->ws->id, ...$others->pluck('id')->all()])
        ->and($second->json('meta.next_cursor'))->toBeNull()
        ->and($second->json('data.0.usage'))->toHaveKeys(['billed_users', 'api_calls_30d']);

    Workspace::query()->update(['updated_at' => now()->subDay()]);
    $others[1]->forceFill(['name' => 'Renamed'])->save();
    internalApi('GET', 'workspaces?updated_since='.urlencode(now()->subHour()->toIso8601String()))->assertOk()
        ->assertJsonPath('data.*.id', [$others[1]->id]);
    internalApi('GET', 'workspaces?cursor=garbage')->assertUnprocessable()->assertJsonValidationErrors('cursor');
});

test('PUT entitlements replaces plan rows only: overrides keep their value, plan_value follows the plan', function () {
    $e = app(Entitlements::class);
    $e->set($this->ws, Entitlement::ApiPerDay, '9000', 'override');
    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements", ['entitlements' => ['projects' => 1, 'viewers' => 3, 'plan' => 'trial']])->assertOk();

    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements", ['entitlements' => ['projects' => 5, 'api_per_day' => 2000, 'webhooks' => true], 'staff' => ADMIN_STAFF])
        ->assertOk()
        ->assertJsonPath('data.entitlements.projects', ['value' => 5, 'source' => 'plan', 'plan_value' => 5])
        ->assertJsonPath('data.entitlements.webhooks', ['value' => true, 'source' => 'plan', 'plan_value' => true]);

    expect(entitlementRow($this->ws, 'api_per_day'))->toBe(['value' => '9000', 'source' => 'override', 'plan_value' => '2000'])
        ->and(entitlementRow($this->ws, 'viewers'))->toBeNull()
        ->and(entitlementRow($this->ws, 'plan'))->toBeNull();

    // Dropped from the plan: the override stays, its plan value goes.
    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements", ['entitlements' => ['projects' => 5]])->assertOk();
    expect(entitlementRow($this->ws, 'api_per_day'))->toBe(['value' => '9000', 'source' => 'override', 'plan_value' => null]);
});

test('PUT and DELETE entitlements/{key} set and remove an override with a reason; removing falls back to the plan value', function () {
    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements", ['entitlements' => ['projects' => 1]])->assertOk();

    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements/projects", ['value' => 10, 'reason' => 'Goodwill', 'staff' => ADMIN_STAFF])->assertOk()
        ->assertJsonPath('data.entitlements.projects', ['value' => 10, 'source' => 'override', 'plan_value' => 1]);
    $row = AuditLog::where('action', 'entitlement.changed')->latest('id')->first();
    expect($row->actor_type)->toBe('staff')
        ->and($row->metadata)->toEqual(['key' => 'projects', 'before' => '1', 'after' => '10', 'source' => 'override', 'reason' => 'Goodwill', 'staff' => ADMIN_STAFF]);

    internalApi('DELETE', "workspaces/{$this->ws->id}/entitlements/projects", ['reason' => 'Ended'])->assertOk()
        ->assertJsonPath('data.entitlements.projects', ['value' => 1, 'source' => 'plan', 'plan_value' => 1]);

    // No plan value: removing the override leaves no row (unlimited).
    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements/github", ['value' => true, 'reason' => 'Beta'])->assertOk();
    internalApi('DELETE', "workspaces/{$this->ws->id}/entitlements/github", ['reason' => 'Beta over'])->assertOk()
        ->assertJsonMissingPath('data.entitlements.github');

    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements/projects", ['value' => 10])->assertUnprocessable()->assertJsonValidationErrors('reason');
    internalApi('DELETE', "workspaces/{$this->ws->id}/entitlements/projects", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements/projects", ['value' => 'lots', 'reason' => 'x'])->assertUnprocessable()
        ->assertJsonPath('errors.value.0', 'projects takes a whole number of 0 or more.');
});

test('an unknown entitlement key lists the valid ones', function () {
    $valid = 'Valid keys: '.implode(', ', array_column(Entitlement::cases(), 'value')).'.';

    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements/seats", ['value' => 1, 'reason' => 'x'])->assertNotFound()
        ->assertJsonPath('errors.key.0', "Unknown entitlement \"seats\". {$valid}");
    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements", ['entitlements' => ['seats' => 1]])->assertUnprocessable()
        ->assertJsonPath('errors', ['entitlements.seats' => ["Unknown entitlement \"seats\". {$valid}"]]);
    expect($this->ws->entitlements()->count())->toBe(0);
});

test('suspend, unsuspend, archive and restore take a reason and each write one audit row', function () {
    $id = $this->ws->id;
    internalApi('POST', "workspaces/{$id}/suspend", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

    internalApi('POST', "workspaces/{$id}/suspend", ['reason' => 'Chargeback', 'staff' => ADMIN_STAFF])->assertOk()->assertJsonPath('data.state', 'suspended');
    internalApi('POST', "workspaces/{$id}/suspend", ['reason' => 'Again'])->assertOk()->assertJsonPath('data.state', 'suspended');
    internalApi('POST', "workspaces/{$id}/unsuspend", ['reason' => 'Paid'])->assertOk()->assertJsonPath('data.state', 'active');
    internalApi('POST', "workspaces/{$id}/archive", ['reason' => 'Inactive 12 months'])->assertOk()->assertJsonPath('data.state', 'archived');
    internalApi('POST', "workspaces/{$id}/restore", ['reason' => 'Asked', 'staff' => ADMIN_STAFF])->assertOk()->assertJsonPath('data.state', 'active');

    $rows = AuditLog::where('workspace_id', $id)->where('action', 'like', 'workspace.%')->where('action', '!=', 'workspace.created')->orderBy('id')->get();
    expect($rows->map->only(['action', 'actor_type', 'actor_user_id'])->all())->toBe([
        ['action' => 'workspace.suspended', 'actor_type' => 'staff', 'actor_user_id' => null],
        ['action' => 'workspace.unsuspended', 'actor_type' => 'system', 'actor_user_id' => null],
        ['action' => 'workspace.archived', 'actor_type' => 'system', 'actor_user_id' => null],
        ['action' => 'workspace.restored', 'actor_type' => 'staff', 'actor_user_id' => null],
    ])->and($rows[0]->metadata)->toEqual(['reason' => 'Chargeback', 'staff' => ADMIN_STAFF])
        ->and($rows[1]->metadata)->toEqual(['reason' => 'Paid'])
        ->and(json_encode(AuditLog::all()))->not->toContain(INTERNAL_TOKEN);
});

test('an unknown workspace is a 404 in the normal error shape', function () {
    internalApi('GET', 'workspaces/999999')->assertNotFound()
        ->assertExactJson(['message' => 'No workspace with id 999999.', 'errors' => ['workspace' => ['No workspace with id 999999.']]]);
    internalApi('POST', 'workspaces/999999/suspend', ['reason' => 'x'])->assertNotFound();
});

test('staff details are checked: a name and a valid email', function () {
    internalApi('POST', "workspaces/{$this->ws->id}/suspend", ['reason' => 'x', 'staff' => ['name' => 'Sue', 'email' => 'not-an-email']])
        ->assertUnprocessable()->assertJsonValidationErrors('staff.email');
    expect($this->ws->fresh()->suspended_at)->toBeNull();
});

test('an override over a default keeps it as the plan value: removing the override goes back to the Free value', function () {
    app(Entitlements::class)->applyFreeDefaults($this->ws);

    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements/projects", ['value' => 5, 'reason' => 'Trial extension'])->assertOk()
        ->assertJsonPath('data.entitlements.projects', ['value' => 5, 'source' => 'override', 'plan_value' => 1]);
    internalApi('DELETE', "workspaces/{$this->ws->id}/entitlements/projects", ['reason' => 'Over'])->assertOk()
        ->assertJsonPath('data.entitlements.projects.value', 1);
});

test('only effective changes are logged: a new plan value under an override writes no audit row', function () {
    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements/projects", ['value' => 10, 'reason' => 'x'])->assertOk();
    $rows = AuditLog::where('action', 'entitlement.changed')->count();

    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements", ['entitlements' => ['projects' => 3]])->assertOk();
    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements", ['entitlements' => ['projects' => 4]])->assertOk();

    expect(entitlementRow($this->ws, 'projects'))->toBe(['value' => '10', 'source' => 'override', 'plan_value' => '4'])
        ->and(AuditLog::where('action', 'entitlement.changed')->count())->toBe($rows);
});
