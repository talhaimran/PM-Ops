<?php

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Issue;
use App\Models\SupportSession;
use App\Models\User;
use App\Services\SupportSessionService;
use Illuminate\Console\Scheduling\Schedule;
use Inertia\Testing\AssertableInertia as Assert;

// 3-R19 / 3-D4: staff view the app as a member through a one-time URL, read-only, with a banner and audit rows.

const SUPPORT_STAFF = ['name' => 'Sue Staff', 'email' => 'sue@pmops.test'];
const READ_ONLY = 'This is a read-only support session. Nothing can be changed.';

beforeEach(function () {
    $this->freezeSecond();
    config(['pm.internal_api_token' => INTERNAL_TOKEN]);
    $this->owner = User::factory()->create();
    $this->target = User::factory()->create(['name' => 'Tara Target', 'email' => 'tara@example.com']);
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->project = makeProject($this->owner, 'WEB');
    addMember($this->project, $this->target, ProjectRole::Member);
    makeIssue($this->project, $this->owner, ['title' => 'First']);
});

function startSupport($t, array $overrides = [])
{
    return internalApi('POST', "workspaces/{$t->ws->id}/support-sessions", [
        'staff' => SUPPORT_STAFF, 'target_user_id' => $t->target->id, 'reason' => 'Ticket 42', 'minutes' => 30, ...$overrides,
    ]);
}

/** A fresh browser opening $url. */
function openSupportUrl($t, string $url)
{
    $t->flushHeaders();
    app('auth')->forgetGuards();

    return $t->get(parse_url($url, PHP_URL_PATH));
}

test('the URL signs in as the target once, without two-factor, and logs staff.support_session_started', function () {
    $this->ws->forceFill(['settings' => [...$this->ws->settings ?? [], 'require_2fa' => true]])->save();
    $response = startSupport($this)->assertCreated();
    $url = $response->json('data.url');

    expect($url)->toStartWith(config('app.url').'/support/')
        ->and(SupportSession::sole()->only(['url_expires_at', 'ends_at']))->toEqual(['url_expires_at' => now()->addMinutes(5), 'ends_at' => now()->addMinutes(30)]);
    openSupportUrl($this, $url)->assertRedirect('/acme/dashboard');
    $this->assertAuthenticatedAs($this->target);
    $this->get('/acme/dashboard')->assertOk();

    $started = AuditLog::where('action', 'staff.support_session_started')->sole();
    expect($started->only(['workspace_id', 'actor_type', 'actor_user_id', 'target_label']))->toBe(['workspace_id' => $this->ws->id, 'actor_type' => 'staff', 'actor_user_id' => null, 'target_label' => 'tara@example.com'])
        ->and($started->metadata)->toMatchArray(['staff' => SUPPORT_STAFF, 'reason' => 'Ticket 42', 'minutes' => 30])
        ->and(json_encode($started))->not->toContain(basename($url))
        ->and(SupportSession::sole()->token_hash)->toBe(hash('sha256', basename($url)))
        // Not an ordinary sign-in by the target.
        ->and(AuditLog::where('action', 'auth.login')->exists())->toBeFalse();

    $this->post('/logout');
    openSupportUrl($this, $url)->assertNotFound();
    $this->assertGuest();
});

test('the URL expires after 5 minutes', function () {
    $url = startSupport($this)->json('data.url');
    $this->travel(301)->seconds();

    openSupportUrl($this, $url)->assertNotFound();
    $this->assertGuest();
});

test('a support session writes nothing: workspace pages, the profile and OAuth consent are refused', function () {
    openSupportUrl($this, startSupport($this)->json('data.url'));
    $before = [Issue::count(), $this->target->fresh()->name];

    $this->post('/acme/projects/WEB/issues', ['title' => 'Staff wrote this'])->assertRedirect()->assertSessionHas('warning', READ_ONLY);
    $this->patch('/acme/issues/WEB-1', ['title' => 'x', 'version' => 1])->assertSessionHas('warning', READ_ONLY);
    $this->post('/acme/notifications/read-all')->assertSessionHas('warning', READ_ONLY);
    $this->patch('/profile', ['name' => 'Hacked', 'email' => 'tara@example.com'])->assertSessionHas('warning', READ_ONLY);
    $this->postJson('/oauth/authorize', ['auth_token' => 'x', 'agent' => 'new'])->assertForbidden()
        ->assertExactJson(['error' => 'support_session_read_only', 'message' => READ_ONLY]);

    // A GET that writes: the sign-in callback would link the staff member's own Google login to the user.
    $this->get('/auth/google/redirect')->assertSessionHas('warning', READ_ONLY);
    $this->get('/auth/google/callback')->assertSessionHas('warning', READ_ONLY);

    expect([Issue::count(), $this->target->fresh()->name])->toBe($before);
});

test('the banner names the staff member, the user and the end time', function () {
    openSupportUrl($this, startSupport($this, ['minutes' => 45])->json('data.url'));

    $this->get('/acme/projects/WEB')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('support_session', ['staff' => 'Sue Staff', 'user' => 'Tara Target', 'ends_at' => now()->addMinutes(45)->format('H:i')]));
});

test('the session is only for its workspace', function () {
    $other = makeWorkspace(User::factory()->create(), 'other');
    $other->addMember($this->target, WorkspaceRole::Member);
    useWorkspace($this->ws);
    openSupportUrl($this, startSupport($this)->json('data.url'));

    $this->get('/other/dashboard')->assertNotFound();
    $this->get('/acme/dashboard')->assertOk();
});

test('the session ends at ends_at: signed out, staff.support_session_ended logged once', function () {
    openSupportUrl($this, startSupport($this, ['minutes' => 10])->json('data.url'));
    $this->get('/acme/dashboard')->assertOk();
    $this->travel(11)->minutes();

    $this->get('/acme/dashboard')->assertRedirect();
    $this->assertGuest();
    $this->get('/acme/dashboard')->assertRedirect('/login');

    $ended = AuditLog::where('action', 'staff.support_session_ended')->sole();
    expect($ended->only(['workspace_id', 'actor_type']))->toBe(['workspace_id' => $this->ws->id, 'actor_type' => 'staff'])
        ->and(SupportSession::sole()->ended_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'auth.logout')->exists())->toBeFalse();
});

test('signing out ends the session with one staff.support_session_ended row', function () {
    openSupportUrl($this, startSupport($this)->json('data.url'));

    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
    expect(AuditLog::where('action', 'staff.support_session_ended')->count())->toBe(1);
});

test('the target must be a member, minutes run 1 to 60, staff and reason are required', function () {
    $stranger = User::factory()->create();

    startSupport($this, ['target_user_id' => $stranger->id])->assertUnprocessable()->assertJsonValidationErrors('target_user_id');
    startSupport($this, ['minutes' => 61])->assertUnprocessable()->assertJsonValidationErrors('minutes');
    startSupport($this, ['minutes' => 0])->assertUnprocessable()->assertJsonValidationErrors('minutes');
    startSupport($this, ['staff' => null])->assertUnprocessable()->assertJsonValidationErrors(['staff.name', 'staff.email']);
    startSupport($this, ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');

    expect(SupportSession::count())->toBe(0)->and(AuditLog::where('action', 'like', 'staff.%')->count())->toBe(0);
});

test('opening a second URL in the same browser ends the first session with its ended row', function () {
    openSupportUrl($this, startSupport($this)->json('data.url'));
    $first = SupportSession::sole();
    $second = startSupport($this)->json('data.url');

    $this->flushHeaders();
    $this->get(parse_url($second, PHP_URL_PATH))->assertRedirect('/acme/dashboard');

    expect($first->fresh()->ended_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'staff.support_session_ended')->count())->toBe(1)
        ->and(session(SupportSessionService::SESSION_KEY))->toBe(SupportSession::latest('id')->first()->id);
});

test('support-sessions:end ends used sessions past ends_at once, even if the browser comes back', function () {
    openSupportUrl($this, startSupport($this, ['minutes' => 10])->json('data.url'));
    startSupport($this, ['minutes' => 5]);  // never opened: nothing to end
    $this->travel(11)->minutes();

    $this->artisan('support-sessions:end')->assertSuccessful();
    $this->artisan('support-sessions:end')->assertSuccessful();
    $this->get('/acme/dashboard')->assertRedirect();
    $this->assertGuest('web');

    expect(AuditLog::where('action', 'staff.support_session_ended')->count())->toBe(1)
        ->and(SupportSession::whereNotNull('ended_at')->count())->toBe(1);
});

test('the scheduler runs support-sessions:end every minute on one server', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'support-sessions:end'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue()->and($event->withoutOverlapping)->toBeTrue();
});

test('audit rows written during a support session name the staff member, not the user', function () {
    $this->ws->members()->updateExistingPivot($this->target->id, ['role' => WorkspaceRole::Admin->value]);
    openSupportUrl($this, startSupport($this)->json('data.url'));

    $this->get('/acme/settings/audit-log/export')->assertOk();

    $row = AuditLog::where('action', 'audit_log.exported')->sole();
    expect($row->only(['actor_type', 'actor_user_id']))->toBe(['actor_type' => 'staff', 'actor_user_id' => null])
        ->and($row->metadata['staff'])->toBe(SUPPORT_STAFF);
});
