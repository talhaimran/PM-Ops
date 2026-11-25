<?php

use App\Enums\ProjectRole;
use App\Models\User;
use JsonSchema\Validator;

// 3-D2: the admin API contract is the JSON Schemas in docs/contract/admin-api, shared with Platform management.

/** @return list<string> the schema errors of $data (raw JSON text, or PHP data) against docs/contract/admin-api/$file */
function contractErrors(string $file, mixed $data): array
{
    $path = base_path("docs/contract/admin-api/{$file}");
    expect($path)->toBeFile();
    // Decoded as objects, so an empty JSON object stays {} and isn't read as an array.
    $value = json_decode(is_string($data) ? $data : json_encode($data));
    $validator = new Validator;
    $validator->validate($value, (object) ['$ref' => 'file://'.$path]);

    return array_map(fn ($e) => "{$e['property']}: {$e['message']}", $validator->getErrors());
}

function expectContract(string $file, mixed $data): void
{
    expect(contractErrors($file, $data))->toBe([], "{$file} does not match");
}

beforeEach(function () {
    config(['pm.internal_api_token' => INTERNAL_TOKEN]);
    $this->owner = User::factory()->create();
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->target = addMember(makeProject($this->owner, 'WEB'), User::factory()->create(), ProjectRole::Member);
});

test('every schema is draft-07 and its examples validate against it', function (string $file) {
    $schema = json_decode(file_get_contents($file), flags: JSON_THROW_ON_ERROR);

    expect($schema->{'$schema'} ?? null)->toBe('http://json-schema.org/draft-07/schema#')
        ->and($schema->examples ?? [])->not->toBeEmpty();
    foreach ($schema->examples as $example) {
        expectContract(basename($file), $example);
    }
})->with(fn () => glob(__DIR__.'/../../../docs/contract/admin-api/*.json'));

test('real responses and the request bodies that produced them match the contract', function () {
    $id = $this->ws->id;
    $calls = [
        ['GET', 'workspaces', null, null, 'workspace-list.json'],
        ['GET', "workspaces/{$id}", null, null, 'workspace.json'],
        ['GET', "workspaces/{$id}/usage", null, null, 'usage.json'],
        ['PUT', "workspaces/{$id}/entitlements", ['entitlements' => ['projects' => 3, 'webhooks' => false, 'plan' => 'paid', 'trial_ends_at' => null]], 'entitlements-request.json', 'workspace.json'],
        ['PUT', "workspaces/{$id}/entitlements/projects", ['value' => 10, 'reason' => 'Goodwill', 'staff' => ['name' => 'Sue', 'email' => 'sue@pmops.test']], 'entitlement-request.json', 'workspace.json'],
        ['DELETE', "workspaces/{$id}/entitlements/projects", ['reason' => 'Ended'], 'reason-request.json', 'workspace.json'],
        ['POST', "workspaces/{$id}/suspend", ['reason' => 'Chargeback'], 'reason-request.json', 'workspace.json'],
        ['POST', "workspaces/{$id}/unsuspend", ['reason' => 'Paid', 'staff' => ['name' => 'Sue', 'email' => 'sue@pmops.test']], 'reason-request.json', 'workspace.json'],
        ['POST', "workspaces/{$id}/archive", ['reason' => 'Inactive'], 'reason-request.json', 'workspace.json'],
        ['POST', "workspaces/{$id}/restore", ['reason' => 'Asked'], 'reason-request.json', 'workspace.json'],
        ['POST', "workspaces/{$id}/support-sessions", ['staff' => ['name' => 'Sue', 'email' => 'sue@pmops.test'], 'target_user_id' => $this->target->id, 'reason' => 'Ticket', 'minutes' => 15], 'support-session-request.json', 'support-session.json'],
        // Errors: unknown workspace, unknown key, validation.
        ['GET', 'workspaces/999999', null, null, 'error.json'],
        ['PUT', "workspaces/{$id}/entitlements/seats", ['value' => 1, 'reason' => 'x'], 'entitlement-request.json', 'error.json'],
        ['POST', "workspaces/{$id}/suspend", [], null, 'error.json'],
    ];

    foreach ($calls as [$method, $uri, $body, $requestSchema, $responseSchema]) {
        if ($requestSchema) {
            expectContract($requestSchema, $body);
        }
        $response = internalApi($method, $uri, $body ?? []);
        expect($response->status())->toBeLessThan(500);
        expectContract($responseSchema, $response->getContent());
    }
});

test('the schemas reject what the API would refuse', function () {
    expect(contractErrors('support-session-request.json', ['staff' => ['name' => 'Sue', 'email' => 'sue@pmops.test'], 'target_user_id' => 1, 'reason' => 'x', 'minutes' => 61]))->not->toBe([])
        ->and(contractErrors('reason-request.json', []))->not->toBe([])
        ->and(contractErrors('workspace.json', ['data' => ['id' => 1, 'state' => 'paused']]))->not->toBe([]);
});
