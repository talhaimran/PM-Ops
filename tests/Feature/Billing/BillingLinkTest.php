<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use JsonSchema\Validator;

// 3-R23: Owners only; a 5-minute single-use token signed with PM_BILLING_LINK_SECRET.

function base64urlDecode(string $value): string
{
    return base64_decode(strtr($value, '-_', '+/'));
}

/** @return array{0: array, 1: string, 2: string} claims, the signed part, the signature (raw, base64url-decoded) */
function billingToken(string $location): array
{
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    [$claims, $signature] = explode('.', $query['token']);

    return [json_decode(base64urlDecode($claims), true), $claims, base64urlDecode($signature)];
}

beforeEach(function () {
    $this->freezeSecond();
    config(['pm.billing_url' => 'https://billing.example.test/base', 'pm.billing_link_secret' => 'billing-secret']);
    $this->owner = User::factory()->create(['name' => 'Olive Owner']);
    $this->ws = makeWorkspace($this->owner, 'acme');
});

test('an Owner is redirected to PM_BILLING_URL/session with a verifiable, schema-valid token', function () {
    $response = $this->actingAs($this->owner)->get('/acme/settings/billing');

    $response->assertRedirectContains('https://billing.example.test/base/session?token=');
    [$claims, $signed, $signature] = billingToken($response->headers->get('Location'));
    expect(hash_equals(hash_hmac('sha256', $signed, 'billing-secret', true), $signature))->toBeTrue()
        ->and($claims)->toMatchArray([
            'workspace_id' => $this->ws->id, 'user_id' => $this->owner->id, 'email' => $this->owner->email, 'name' => 'Olive Owner',
            'role' => 'owner', 'iat' => now()->timestamp,
        ])
        ->and($claims['exp'] - $claims['iat'])->toBe(300)
        ->and($claims['nonce'])->toMatch('/^[0-9a-f]{64}$/');

    $validator = new Validator;
    $value = json_decode(json_encode($claims));
    $validator->validate($value, (object) ['$ref' => 'file://'.base_path('docs/contract/billing-token.json')]);
    expect($validator->getErrors())->toBe([]);
});

test('each visit gets a fresh nonce', function () {
    $nonces = collect([1, 2])->map(fn () => billingToken($this->actingAs($this->owner)->get('/acme/settings/billing')->headers->get('Location'))[0]['nonce']);

    expect($nonces->unique())->toHaveCount(2);
});

test('Admins and Members get 403', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $this->ws->addMember($user, $role);

    $this->actingAs($user)->get('/acme/settings/billing')->assertForbidden();
})->with([WorkspaceRole::Admin, WorkspaceRole::Member]);

test('the link is off unless both the URL and the secret are set', function (array $config) {
    config($config);

    $this->actingAs($this->owner)->get('/acme/settings/billing')->assertNotFound();
})->with([
    'no url' => [['pm.billing_url' => null]],
    'no secret' => [['pm.billing_link_secret' => null]],
]);

test('it works in a suspended workspace: paying is how a suspension ends', function () {
    $this->ws->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($this->owner)->get('/acme/settings/billing')->assertRedirectContains('https://billing.example.test/base/session?token=');
});
