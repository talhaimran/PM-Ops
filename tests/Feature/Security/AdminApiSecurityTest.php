<?php

use App\Enums\ProjectRole;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// Review Focus 5 (3-R18): forged, missing, old and replayed admin calls.

beforeEach(function () {
    config(['pm.internal_api_token' => INTERNAL_TOKEN, 'pm.internal_api_token_next' => null]);
    $this->owner = User::factory()->create();
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->target = addMember(makeProject($this->owner, 'WEB'), User::factory()->create(), ProjectRole::Member);
});

$unauthenticated = ['message' => 'Unauthenticated.'];

test('no token, a wrong token, an old token or a user\'s Sanctum token: 401 with no detail', function () use ($unauthenticated) {
    [, $sanctum] = makeAgent($this->owner);

    foreach (['', 'wrong-token', INTERNAL_TOKEN.'x', 'internal-test-toke', $sanctum] as $token) {
        internalApi('GET', "workspaces/{$this->ws->id}", token: $token)->assertUnauthorized()->assertExactJson($unauthenticated);
        internalApi('POST', "workspaces/{$this->ws->id}/suspend", ['reason' => 'x'], token: $token)->assertUnauthorized()->assertExactJson($unauthenticated);
    }
    expect($this->ws->fresh()->suspended_at)->toBeNull();
});

test('a user\'s OAuth (MCP) token is a 401', function () use ($unauthenticated) {
    $tokens = oauthConnect($this->owner, 'new');

    internalApi('GET', 'workspaces', token: $tokens['access_token'])->assertUnauthorized()->assertExactJson($unauthenticated);
});

test('a user\'s web session is a 401: the internal API has no session', function () use ($unauthenticated) {
    $this->actingAs($this->owner)->getJson('/internal/v1/workspaces')->assertUnauthorized()->assertExactJson($unauthenticated);
});

test('PM_INTERNAL_API_TOKEN_NEXT is accepted too, for rotation', function () {
    config(['pm.internal_api_token_next' => 'next-token']);

    internalApi('GET', "workspaces/{$this->ws->id}", token: 'next-token')->assertOk();
    internalApi('GET', "workspaces/{$this->ws->id}")->assertOk();
});

test('with no token configured, every path is a 404, whatever is sent', function (string $method, string $path) {
    config(['pm.internal_api_token' => null, 'pm.internal_api_token_next' => 'next-token']);

    foreach ([INTERNAL_TOKEN, 'next-token', ''] as $token) {
        internalApi($method, str_replace('{id}', (string) $this->ws->id, $path), ['reason' => 'x'], token: $token)->assertNotFound();
    }
    expect($this->ws->fresh()->suspended_at)->toBeNull();
})->with([
    ['GET', 'workspaces'], ['GET', 'workspaces/{id}'], ['GET', 'workspaces/{id}/usage'], ['PUT', 'workspaces/{id}/entitlements'],
    ['PUT', 'workspaces/{id}/entitlements/projects'], ['DELETE', 'workspaces/{id}/entitlements/projects'], ['POST', 'workspaces/{id}/suspend'],
    ['POST', 'workspaces/{id}/unsuspend'], ['POST', 'workspaces/{id}/archive'], ['POST', 'workspaces/{id}/restore'],
    ['POST', 'workspaces/{id}/support-sessions'], ['GET', 'nothing-here'],
]);

test('a repeated Idempotency-Key replays the first answer without a second write or audit row', function () {
    $body = ['staff' => ['name' => 'Sue', 'email' => 'sue@pmops.test'], 'target_user_id' => $this->target->id, 'reason' => 'Ticket', 'minutes' => 10];

    $first = internalApi('POST', "workspaces/{$this->ws->id}/support-sessions", $body, key: 'key-1')->assertCreated();
    $again = internalApi('POST', "workspaces/{$this->ws->id}/support-sessions", $body, key: 'key-1')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data.url'))->toBe($first->json('data.url'))
        ->and(SupportSession::count())->toBe(1)
        ->and(AuditLog::where('action', 'staff.support_session_started')->count())->toBe(1);

    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements/projects", ['value' => 3, 'reason' => 'x'], key: 'key-2')->assertOk();
    internalApi('PUT', "workspaces/{$this->ws->id}/entitlements/projects", ['value' => 3, 'reason' => 'x'], key: 'key-2')->assertHeader('Idempotent-Replayed', 'true');
    internalApi('DELETE', "workspaces/{$this->ws->id}/entitlements/projects", ['reason' => 'x'], key: 'key-3')->assertOk();
    internalApi('DELETE', "workspaces/{$this->ws->id}/entitlements/projects", ['reason' => 'x'], key: 'key-3')->assertHeader('Idempotent-Replayed', 'true');
    expect(AuditLog::where('action', 'entitlement.changed')->count())->toBe(2);

    // The same key with another body is refused, not replayed.
    internalApi('POST', "workspaces/{$this->ws->id}/support-sessions", [...$body, 'minutes' => 20], key: 'key-1')->assertUnprocessable();
});

test('a write without an Idempotency-Key is a 400 and changes nothing', function (string $method, string $path, array $body) {
    internalApi($method, str_replace('{id}', (string) $this->ws->id, $path), $body, key: '')->assertStatus(400)
        ->assertJsonPath('message', 'An Idempotency-Key header is required.');
    expect($this->ws->fresh()->suspended_at)->toBeNull()->and(AuditLog::where('actor_type', '!=', 'user')->count())->toBe(0);
})->with([
    ['POST', 'workspaces/{id}/suspend', ['reason' => 'x']],
    ['PUT', 'workspaces/{id}/entitlements/projects', ['value' => 1, 'reason' => 'x']],
    ['DELETE', 'workspaces/{id}/entitlements/projects', ['reason' => 'x']],
    ['PUT', 'workspaces/{id}/entitlements', ['entitlements' => []]],
]);

test('the /api/v1 Idempotency-Key stays optional, and now also replays PUT and DELETE', function () {
    [, $plain] = makeAgent($this->owner);
    makeIssue(Project::sole(), $this->owner);

    asToken($plain)->deleteJson('/api/v1/issues/WEB-1', ['confirm' => true])->assertNoContent();
    asToken($plain, ['Idempotency-Key' => 'del-1'])->postJson('/api/v1/projects/WEB/issues', ['title' => 'x'])->assertCreated();
    asToken($plain, ['Idempotency-Key' => 'del-2'])->deleteJson('/api/v1/issues/WEB-2', ['confirm' => true])->assertNoContent();
    asToken($plain, ['Idempotency-Key' => 'del-2'])->deleteJson('/api/v1/issues/WEB-2', ['confirm' => true])->assertNoContent()->assertHeader('Idempotent-Replayed', 'true');
});

test('the service token is never logged, returned or audited', function () {
    Log::spy();
    internalApi('POST', "workspaces/{$this->ws->id}/suspend", ['reason' => 'x'])->assertOk();
    $response = internalApi('GET', "workspaces/{$this->ws->id}", token: 'wrong')->assertUnauthorized();

    expect($response->getContent())->not->toContain(INTERNAL_TOKEN)->not->toContain('wrong')
        ->and(json_encode(AuditLog::all()))->not->toContain(INTERNAL_TOKEN);
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});
