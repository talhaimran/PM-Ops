<?php

use App\Enums\AiProviderType;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\ProjectAgentSettings;
use App\Models\User;
use App\Services\AiSettingsService;
use Inertia\Testing\AssertableInertia as Assert;

// Spec 8 (A5): the workspace's AI connection (Settings → AI, Owners and Admins), the Mode C switches and their audit trail.

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->project = makeProject($this->owner, 'WEB');
    $this->admin = User::factory()->create();
    $this->ws->addMember($this->admin, WorkspaceRole::Admin);
    $this->member = User::factory()->create();
    $this->ws->addMember($this->member, WorkspaceRole::Member);
    $this->ai = app(AiSettingsService::class);
    $this->payload = ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'endpoint' => '', 'api_key' => 'sk-test-SECRET'];
});

function envAi(array $values): void
{
    config(['pm.ai' => [...config('pm.ai'), ...$values]]);
}

test('Owners and Admins save provider, model, endpoint and key; the page and the service never return the key', function () {
    foreach ([$this->owner, $this->admin] as $user) {
        AiProvider::query()->delete();
        $this->actingAs($user)->patch(w('/settings/ai'), [...$this->payload, 'provider' => 'openai_compatible', 'endpoint' => 'https://gateway.example.com/v1'])
            ->assertSessionHasNoErrors()->assertRedirect();

        expect($this->ai->for($this->ws))->toBe(['provider' => AiProviderType::OpenAiCompatible, 'model' => 'claude-sonnet-4-5', 'endpoint' => 'https://gateway.example.com/v1', 'source' => 'workspace'])
            ->and($this->ai->key($this->ws))->toBe('sk-test-SECRET');
    }

    $this->get(w('/settings/ai'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Workspaces/Settings/Ai')
        ->where('connection.source', 'workspace')->where('connection.provider', 'openai_compatible')->where('connection.model', 'claude-sonnet-4-5')
        ->where('connection.endpoint', 'https://gateway.example.com/v1')->where('connection.has_key', true)
        ->where('connection.cap', null)->where('connection.used', 0)->where('mode_c', true));
});

test('Members cannot open or change anything under Settings, AI', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload);
    $this->actingAs($this->member)->get(w('/settings/ai'))->assertForbidden();
    $this->patch(w('/settings/ai'), [...$this->payload, 'model' => 'other'])->assertForbidden();
    $this->patch(w('/settings/ai/cap'), ['monthly_token_cap' => 5])->assertForbidden();
    $this->patch(w('/settings/ai/mode-c'), ['mode_c_enabled' => false])->assertForbidden();
    $this->delete(w('/settings/ai'))->assertForbidden();

    expect(AiProvider::sole()->model)->toBe('claude-sonnet-4-5')->and($this->ws->fresh()->setting('mode_c_enabled'))->toBeTrue();
});

test('endpoint is required for openai_compatible and must be https or localhost http, with no login or query', function (string $endpoint, bool $ok) {
    $response = $this->actingAs($this->owner)->patch(w('/settings/ai'), [...$this->payload, 'provider' => 'openai_compatible', 'endpoint' => $endpoint]);

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('endpoint');
})->with([
    'missing' => ['', false],
    'plain http' => ['http://gateway.example.com/v1', false],
    'ftp' => ['ftp://gateway.example.com', false],
    'not a url' => ['gateway', false],
    'login in the url' => ['https://user:pass@gateway.example.com/v1', false],
    'token in the query' => ['https://gateway.example.com/v1?token=abc', false],
    'https' => ['https://gateway.example.com/v1', true],
    'http localhost' => ['http://localhost:11434/v1', true],
    'http 127.0.0.1' => ['http://127.0.0.1:11434/v1', true],
]);

test('the audit row keeps only scheme, host and port of the endpoint and says whether it changed', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), [...$this->payload, 'provider' => 'openai_compatible', 'endpoint' => 'https://gw.example.com:8443/v1/keys/sk-path-SECRET'])->assertSessionHasNoErrors();

    $row = AuditLog::where('action', 'ai_provider.updated')->sole();
    expect($row->metadata)->toEqual(['provider' => 'openai_compatible', 'model' => 'claude-sonnet-4-5', 'endpoint' => 'https://gw.example.com:8443', 'endpoint_changed' => true])
        ->and(json_encode(AuditLog::all()->map->getAttributes()))->not->toContain('sk-path-SECRET');
    // The Admin's own form still shows the full address.
    $this->get(w('/settings/ai'))->assertInertia(fn (Assert $page) => $page->where('connection.endpoint', 'https://gw.example.com:8443/v1/keys/sk-path-SECRET'));

    $this->patch(w('/settings/ai'), [...$this->payload, 'provider' => 'openai_compatible', 'endpoint' => 'https://gw.example.com:8443/v1/keys/sk-path-SECRET', 'model' => 'm2', 'api_key' => '']);
    expect(AuditLog::where('action', 'ai_provider.updated')->latest('id')->first()->metadata['endpoint_changed'])->toBeFalse();
});

test('an endpoint set on the server shows no path either', function () {
    envAi(['provider' => 'openai_compatible', 'model' => 'm', 'key' => 'k', 'endpoint' => 'https://gw.example.com/v1/keys/sk-path-SECRET']);

    $response = $this->actingAs($this->owner)->get(w('/settings/ai'))->assertInertia(fn (Assert $page) => $page->where('connection.endpoint', 'https://gw.example.com'));
    expect($response->getContent())->not->toContain('sk-path-SECRET');
});

test('a save-time check refuses private, loopback, link-local, reserved, CGNAT, multicast and metadata addresses', function (string $endpoint) {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), [...$this->payload, 'provider' => 'openai_compatible', 'endpoint' => $endpoint])
        ->assertSessionHasErrors('endpoint');

    expect(AiProvider::count())->toBe(0);
})->with([
    '10/8' => 'https://10.0.0.5', '172.16/12' => 'https://172.16.1.1', '192.168/16' => 'https://192.168.1.10',
    'metadata ip' => 'https://169.254.169.254', 'link-local' => 'https://169.254.1.1', 'CGNAT' => 'https://100.64.0.1', 'CGNAT top' => 'https://100.127.255.254',
    'loopback range' => 'https://127.0.0.2', 'this network' => 'https://0.0.0.0', 'multicast' => 'https://224.0.0.1', 'reserved' => 'https://240.0.0.1', 'broadcast' => 'https://255.255.255.255',
    'mapped loopback' => 'https://[::ffff:127.0.0.1]', 'mapped loopback hex' => 'https://[::ffff:7f00:1]', 'mapped private' => 'https://[::ffff:10.0.0.1]',
    'link-local v6' => 'https://[fe80::1]', 'unique local' => 'https://[fd00::1]', 'unique local fc' => 'https://[fc00::1]', 'multicast v6' => 'https://[ff02::1]',
    'unspecified v6' => 'https://[::]', 'NAT64 private' => 'https://[64:ff9b::a00:1]', 'bad v6' => 'https://[fe80::1%25eth0]',
    'decimal ip' => 'https://2130706433', 'short ip' => 'https://127.1', 'hex ip' => 'https://0x7f.0.0.1',
    'metadata name' => 'https://metadata.google.internal', 'metadata short' => 'https://metadata', 'instance-data' => 'https://instance-data',
    'documentation v6' => 'https://[2001:db8::1]', 'benchmarking v6' => 'https://[2001:2::1]', 'teredo' => 'https://[2001::1]', 'orchid' => 'https://[2001:10::1]',
    'orchid v2' => 'https://[2001:20::1]', 'documentation 3fff' => 'https://[3fff::1]', '6to4' => 'https://[2002:c000:0201::1]', 'discard' => 'https://[100::1]',
    'NAT64 local-use' => 'https://[64:ff9b:1::1]', 'IETF block top' => 'https://[2001:1ff::1]', 'not global unicast' => 'https://[4000::1]',
    'documentation 1' => 'https://192.0.2.1', 'benchmarking' => 'https://198.18.0.1', 'documentation 2' => 'https://198.51.100.7', 'documentation 3' => 'https://203.0.113.9',
    'IETF assignments' => 'https://192.0.0.8', '6to4 relay' => 'https://192.88.99.1', 'Azure platform' => 'https://168.63.129.16', 'Azure platform mapped' => 'https://[::ffff:168.63.129.16]',
    'dot internal' => 'https://foo.internal', 'case and dot' => 'https://Metadata.Google.Internal.', 'with port' => 'https://10.0.0.5:8443/v1',
]);

test('an endpoint that is not a string is a validation error, not a server error', function (mixed $endpoint) {
    $this->actingAs($this->owner)->patchJson(w('/settings/ai'), [...$this->payload, 'provider' => 'openai_compatible', 'endpoint' => $endpoint])
        ->assertUnprocessable()->assertJsonValidationErrors('endpoint');

    expect(AiProvider::count())->toBe(0);
})->with(['list' => [['https://api.example.com']], 'object' => [['url' => 'https://api.example.com']], 'number' => 12345]);

test('public addresses pass the save-time check', function (string $endpoint) {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), [...$this->payload, 'provider' => 'openai_compatible', 'endpoint' => $endpoint])
        ->assertSessionHasNoErrors();
})->with(['name' => 'https://api.example.com', 'name and path' => 'https://api.example.com:8443/v1', 'public v4' => 'https://8.8.8.8', 'public v6' => 'https://[2606:4700:4700::1111]', 'internal in a name' => 'https://internal.example.com', 'v6 just outside 2001::/23' => 'https://[2001:200::1]', 'v6 just outside 2001:db8' => 'https://[2001:db9::1]']);

test('local model addresses are allowed on a self-hosted install and refused on Cloud with their own message', function (string $endpoint) {
    $payload = [...$this->payload, 'provider' => 'openai_compatible', 'endpoint' => $endpoint];

    config(['pm.edition' => 'self_hosted']);
    $this->actingAs($this->owner)->patch(w('/settings/ai'), $payload)->assertSessionHasNoErrors();

    config(['pm.edition' => 'cloud']);
    $this->patch(w('/settings/ai'), $payload)->assertSessionHasErrors(['endpoint' => 'A local model address is only allowed on a self-hosted install.']);
})->with(['localhost' => 'http://localhost:11434/v1', 'loopback' => 'http://127.0.0.1:11434/v1', 'loopback v6' => 'http://[::1]:11434/v1']);

test('the other providers may leave the endpoint empty; the key is required the first time; the provider must be known', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), [...$this->payload, 'api_key' => ''])->assertSessionHasErrors('api_key');
    $this->patch(w('/settings/ai'), [...$this->payload, 'provider' => 'nope'])->assertSessionHasErrors('provider');
    $this->patch(w('/settings/ai'), [...$this->payload, 'model' => ''])->assertSessionHasErrors('model');
    expect(AiProvider::count())->toBe(0);

    $this->patch(w('/settings/ai'), $this->payload)->assertSessionHasNoErrors();
    // A local model needs no key.
    AiProvider::query()->delete();
    $this->patch(w('/settings/ai'), ['provider' => 'openai_compatible', 'model' => 'llama3', 'endpoint' => 'http://localhost:11434/v1', 'api_key' => ''])->assertSessionHasNoErrors();
    expect($this->ai->key($this->ws))->toBeNull();
});

test('leaving the key blank keeps it; the key follows its provider and endpoint, so changing either asks for a new one', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload);

    $this->patch(w('/settings/ai'), [...$this->payload, 'model' => 'claude-opus-4-5', 'api_key' => ''])->assertSessionHasNoErrors();
    expect($this->ai->for($this->ws)['model'])->toBe('claude-opus-4-5')->and($this->ai->key($this->ws))->toBe('sk-test-SECRET');

    $this->patch(w('/settings/ai'), [...$this->payload, 'provider' => 'openai', 'api_key' => ''])->assertSessionHasErrors('api_key');
    $this->patch(w('/settings/ai'), [...$this->payload, 'endpoint' => 'https://proxy.example.com', 'api_key' => ''])->assertSessionHasErrors('api_key');
    expect($this->ai->for($this->ws)['provider'])->toBe(AiProviderType::Anthropic)->and($this->ai->key($this->ws))->toBe('sk-test-SECRET');

    // Without a required key, a moved endpoint drops the old key rather than sending it somewhere new.
    AiProvider::query()->delete();
    $local = ['provider' => 'openai_compatible', 'model' => 'llama3', 'endpoint' => 'http://localhost:11434/v1'];
    $this->patch(w('/settings/ai'), [...$local, 'api_key' => 'sk-local']);
    $this->patch(w('/settings/ai'), [...$local, 'endpoint' => 'https://gateway.example.com/v1', 'api_key' => '']);
    expect($this->ai->key($this->ws))->toBeNull();
});

test('audit: updated carries provider, model and endpoint; key_changed carries no key; unchanged saves log nothing', function () {
    $this->actingAs($this->admin)->patch(w('/settings/ai'), $this->payload);

    expect(AuditLog::where('action', 'ai_provider.updated')->sole()->metadata)->toEqual(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'endpoint' => null, 'endpoint_changed' => false]);
    $key = AuditLog::where('action', 'ai_provider.key_changed')->sole();
    expect($key->metadata)->toBeNull()->and($key->actor_user_id)->toBe($this->admin->id)->and($key->target_label)->toBe('Acme');

    $this->patch(w('/settings/ai'), [...$this->payload, 'api_key' => '']);
    expect(AuditLog::where('action', 'like', 'ai_provider.%')->count())->toBe(2);

    $this->patch(w('/settings/ai'), [...$this->payload, 'model' => 'm2', 'api_key' => '']);
    expect(AuditLog::where('action', 'ai_provider.updated')->count())->toBe(2)->and(AuditLog::where('action', 'ai_provider.key_changed')->count())->toBe(1);

    $this->patch(w('/settings/ai'), [...$this->payload, 'api_key' => 'sk-test-NEW']);
    expect(AuditLog::where('action', 'ai_provider.key_changed')->count())->toBe(2)->and(AuditLog::where('action', 'ai_provider.updated')->count())->toBe(3);
});

test('removing the provider deletes the key and logs ai_provider.removed; removing again does nothing', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload);

    $this->delete(w('/settings/ai'))->assertRedirect();

    expect(AiProvider::sole()->only(['provider', 'model', 'endpoint']))->toBe(['provider' => null, 'model' => null, 'endpoint' => null])
        ->and($this->ai->for($this->ws))->toBeNull()->and($this->ai->key($this->ws))->toBeNull()->and($this->ai->hasKey($this->ws))->toBeFalse()
        ->and(AuditLog::where('action', 'ai_provider.removed')->sole()->metadata)->toEqual(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5']);
    $this->delete(w('/settings/ai'))->assertRedirect();
    expect(AuditLog::where('action', 'ai_provider.removed')->count())->toBe(1);
});

test('removing the provider keeps the monthly cap and this month\'s usage, which are still there after setting a provider again', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload);
    $this->patch(w('/settings/ai/cap'), ['monthly_token_cap' => 5000]);
    AiProvider::sole()->update(['tokens_used_month' => 1234]);

    $this->delete(w('/settings/ai'))->assertRedirect();
    expect($this->ai->usage($this->ws))->toBe(['cap' => 5000, 'used' => 1234])
        ->and(AuditLog::where('action', 'ai_provider.removed')->count())->toBe(1);
    $this->get(w('/settings/ai'))->assertInertia(fn (Assert $page) => $page->where('connection', null));

    $this->patch(w('/settings/ai'), $this->payload)->assertSessionHasNoErrors();
    expect($this->ai->usage($this->ws))->toBe(['cap' => 5000, 'used' => 1234])->and($this->ai->for($this->ws))->not->toBeNull();
});

test('the monthly token cap is optional, needs a provider, and logs before and after', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai/cap'), ['monthly_token_cap' => 1000])->assertSessionHasErrors('monthly_token_cap');
    $this->patch(w('/settings/ai'), $this->payload);

    $this->patch(w('/settings/ai/cap'), ['monthly_token_cap' => 1000])->assertSessionHasNoErrors();
    expect(AiProvider::sole()->monthly_token_cap)->toBe(1000)
        ->and(AuditLog::where('action', 'ai_provider.cap_changed')->sole()->metadata)->toEqual(['before' => null, 'after' => 1000]);

    $this->patch(w('/settings/ai/cap'), ['monthly_token_cap' => 1000]);
    expect(AuditLog::where('action', 'ai_provider.cap_changed')->count())->toBe(1);

    foreach ([0, -1, 'lots'] as $bad) {
        $this->patch(w('/settings/ai/cap'), ['monthly_token_cap' => $bad])->assertSessionHasErrors('monthly_token_cap');
    }
    $this->patch(w('/settings/ai/cap'), ['monthly_token_cap' => null])->assertSessionHasNoErrors();
    expect(AiProvider::sole()->monthly_token_cap)->toBeNull()
        ->and(AuditLog::where('action', 'ai_provider.cap_changed')->latest('id')->first()->metadata)->toEqual(['before' => 1000, 'after' => null]);
});

test('with PM_AI_PROVIDER set the page is read-only, says so, and every write is refused; the key stays on the server', function () {
    envAi(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'endpoint' => null, 'key' => 'sk-env-SECRET']);

    expect($this->ai->for($this->ws))->toBe(['provider' => AiProviderType::Anthropic, 'model' => 'claude-haiku-4-5', 'endpoint' => null, 'source' => 'env'])
        ->and($this->ai->key($this->ws))->toBe('sk-env-SECRET');

    $this->actingAs($this->owner)->get(w('/settings/ai'))->assertInertia(fn (Assert $page) => $page
        ->where('connection.source', 'env')->where('connection.model', 'claude-haiku-4-5')->where('connection.has_key', true));

    $this->patch(w('/settings/ai'), $this->payload)->assertSessionHasErrors('provider');
    $this->delete(w('/settings/ai'))->assertSessionHasErrors('provider');
    expect(AiProvider::count())->toBe(0)->and(AuditLog::where('action', 'like', 'ai_provider.%')->count())->toBe(0);
});

test('an endpoint set on the server is shown as scheme, host and port only', function () {
    envAi(['provider' => 'openai_compatible', 'model' => 'm', 'key' => 'k', 'endpoint' => 'HTTPS://gw.example.com:8443/v1/keys/sk-path-SECRET']);

    $response = $this->actingAs($this->owner)->get(w('/settings/ai'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('connection.endpoint', 'https://gw.example.com:8443')->where('connection.source', 'env'));
    expect($response->getContent())->not->toContain('sk-path-SECRET');
    // The raw value stays available to the provider calls.
    expect($this->ai->for($this->ws)['endpoint'])->toContain('sk-path-SECRET');

    envAi(['endpoint' => null, 'provider' => 'anthropic']);
    $this->get(w('/settings/ai'))->assertInertia(fn (Assert $page) => $page->where('connection.endpoint', null));
});

test('with PM_AI_PROVIDER set the monthly cap is still editable, in a row that holds only the cap', function () {
    envAi(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'key' => 'sk-env-SECRET']);

    $this->actingAs($this->owner)->patch(w('/settings/ai/cap'), ['monthly_token_cap' => 500])->assertSessionHasNoErrors();
    $this->patch(w('/settings/ai/cap'), ['monthly_token_cap' => 900]);

    $row = AiProvider::sole();
    expect($row->provider)->toBeNull()->and($row->model)->toBeNull()->and($row->monthly_token_cap)->toBe(900)
        ->and($this->ai->for($this->ws)['source'])->toBe('env')->and($this->ai->key($this->ws))->toBe('sk-env-SECRET')
        ->and(AuditLog::where('action', 'ai_provider.cap_changed')->oldest('id')->get()->map->metadata->all())->toEqual([['before' => null, 'after' => 500], ['before' => 500, 'after' => 900]]);
    $this->get(w('/settings/ai'))->assertInertia(fn (Assert $page) => $page->where('connection.source', 'env')->where('connection.cap', 900)->where('connection.used', 0));
    // The provider itself stays the server's.
    $this->patch(w('/settings/ai'), $this->payload)->assertSessionHasErrors('provider');
});

test('a cap-only row is not a provider: ai.enabled stays false, the form says none yet, and saving one keeps the cap', function () {
    AiProvider::create(['workspace_id' => $this->ws->id, 'monthly_token_cap' => 5]);

    expect($this->ai->for($this->ws))->toBeNull()->and($this->ai->hasKey($this->ws))->toBeFalse();
    $this->actingAs($this->owner)->get(w('/settings/ai'))->assertInertia(fn (Assert $page) => $page->where('connection', null)->where('ai', ['enabled' => false]));
    $this->delete(w('/settings/ai'))->assertRedirect();
    expect(AuditLog::where('action', 'ai_provider.removed')->count())->toBe(0);

    $this->patch(w('/settings/ai'), $this->payload)->assertSessionHasNoErrors();
    expect(AiProvider::sole()->monthly_token_cap)->toBe(5)->and($this->ai->for($this->ws)['provider'])->toBe(AiProviderType::Anthropic);
});

test('the scheme is case-insensitive and stored in lowercase', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), [...$this->payload, 'provider' => 'openai_compatible', 'endpoint' => 'HTTPS://Gateway.example.com/v1'])->assertSessionHasNoErrors();

    expect($this->ai->for($this->ws)['endpoint'])->toBe('https://Gateway.example.com/v1');
    $this->patch(w('/settings/ai'), [...$this->payload, 'provider' => 'openai_compatible', 'endpoint' => 'HTTP://example.com'])->assertSessionHasErrors('endpoint');
});

test('a PM_AI_PROVIDER that is set but not used is ignored, with a warning that echoes no values', function (array $env, ?string $warning) {
    envAi(['endpoint' => null, 'key' => null, ...$env]);

    $response = $this->actingAs($this->owner)->get(w('/settings/ai'))
        ->assertInertia(fn (Assert $page) => $page->where('env_warning', $warning)->where('connection', null)->where('ai', ['enabled' => false]));
    foreach (['bogus-value', 'sk-env-SECRET', '169.254'] as $value) {
        expect($response->getContent())->not->toContain($value);
    }
    expect($this->ai->modeCEnabled($this->project))->toBeFalse();
})->with([
    'unknown provider' => [['provider' => 'bogus-value', 'model' => 'm', 'key' => 'sk-env-SECRET'], 'PM_AI_PROVIDER is set on your server but isn’t used: it must be one of anthropic, openai, google, openai_compatible, with PM_AI_MODEL set.'],
    'no model' => [['provider' => 'openai', 'model' => null, 'key' => 'sk-env-SECRET'], 'PM_AI_PROVIDER is set on your server but isn’t used: it must be one of anthropic, openai, google, openai_compatible, with PM_AI_MODEL set.'],
    'no key' => [['provider' => 'anthropic', 'model' => 'm'], 'PM_AI_PROVIDER is set on your server but the connection is incomplete: PM_AI_KEY is missing.'],
    'compatible without endpoint' => [['provider' => 'openai_compatible', 'model' => 'm'], 'PM_AI_PROVIDER is set on your server but the connection is incomplete: PM_AI_ENDPOINT is missing.'],
    'metadata endpoint' => [['provider' => 'openai_compatible', 'model' => 'm', 'key' => 'sk-env-SECRET', 'endpoint' => 'https://169.254.169.254/latest'], 'PM_AI_PROVIDER is set on your server but the connection can’t be used: PM_AI_ENDPOINT is not allowed (it must be a public https:// address with no login or query).'],
    'login in endpoint' => [['provider' => 'anthropic', 'model' => 'm', 'key' => 'sk-env-SECRET', 'endpoint' => 'https://user:pw@gw.example.com/v1'], 'PM_AI_PROVIDER is set on your server but the connection can’t be used: PM_AI_ENDPOINT is not allowed (it must be a public https:// address with no login or query).'],
    'missing key and endpoint' => [['provider' => 'openai_compatible', 'model' => 'm', 'key' => null], 'PM_AI_PROVIDER is set on your server but the connection is incomplete: PM_AI_ENDPOINT is missing.'],
    'unset' => [['provider' => null, 'model' => null], null],
]);

test('an unusable server config is ignored, so Admins can still set their own provider', function () {
    envAi(['provider' => 'anthropic', 'model' => 'm', 'key' => null]);

    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload)->assertSessionHasNoErrors();

    expect($this->ai->for($this->ws)['source'])->toBe('workspace')->and($this->ai->key($this->ws))->toBe('sk-test-SECRET');
});

test('a complete server config is read-only and enabled, with no warning', function () {
    envAi(['provider' => 'openai_compatible', 'model' => 'm', 'key' => 'k', 'endpoint' => 'https://gw.example.com/v1']);

    $this->actingAs($this->owner)->get(w('/settings/ai'))->assertInertia(fn (Assert $page) => $page
        ->where('env_warning', null)->where('connection.source', 'env')->where('ai', ['enabled' => true]));
    expect($this->ai->modeCEnabled($this->project))->toBeTrue();
});

test('a local server endpoint is usable on a self-hosted install and ignored on Cloud', function () {
    envAi(['provider' => 'openai_compatible', 'model' => 'm', 'key' => null, 'endpoint' => 'http://localhost:11434/v1']);
    expect($this->ai->for($this->ws)['source'])->toBe('env');

    config(['pm.edition' => 'cloud']);
    expect($this->ai->for($this->ws))->toBeNull();
});

test('a stored provider that stopped being usable is not a provider, and its key still does not follow a new endpoint', function () {
    $local = ['provider' => 'openai_compatible', 'model' => 'm', 'endpoint' => 'http://localhost:11434/v1'];
    $this->actingAs($this->owner)->patch(w('/settings/ai'), [...$local, 'api_key' => 'sk-local']);

    config(['pm.edition' => 'cloud']);
    expect($this->ai->for($this->ws))->toBeNull()->and($this->ai->modeCEnabled($this->project))->toBeFalse();

    $this->patch(w('/settings/ai'), [...$local, 'endpoint' => 'https://gw.example.com/v1', 'api_key' => '']);
    expect($this->ai->key($this->ws))->toBeNull();
});

test('the environment wins over a stored provider, and an unknown PM_AI_PROVIDER is ignored', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload);

    envAi(['provider' => 'openai', 'model' => 'gpt-5', 'key' => 'sk-env']);
    expect($this->ai->for($this->ws)['source'])->toBe('env')->and($this->ai->for($this->ws)['provider'])->toBe(AiProviderType::OpenAi);

    envAi(['provider' => 'bogus']);
    expect($this->ai->for($this->ws)['source'])->toBe('workspace');
});

test('ai.enabled is shared as a bare boolean: false without a provider, true with one', function () {
    $this->actingAs($this->owner)->get(w('/dashboard'))->assertInertia(fn (Assert $page) => $page->where('ai', ['enabled' => false]));
    $this->patch(w('/settings/ai'), $this->payload);
    $this->get(w('/dashboard'))->assertInertia(fn (Assert $page) => $page->where('ai', ['enabled' => true]));

    $this->delete(w('/settings/ai'));
    $this->get(w('/dashboard'))->assertInertia(fn (Assert $page) => $page->where('ai', ['enabled' => false]));
});

test('Owners and Admins turn Mode C off and on workspace-wide; it logs agent_policy.changed with before and after', function () {
    $this->actingAs($this->admin)->patch(w('/settings/ai/mode-c'), ['mode_c_enabled' => false])->assertRedirect();

    expect($this->ws->fresh()->setting('mode_c_enabled'))->toBeFalse()
        ->and(AuditLog::where('action', 'agent_policy.changed')->sole()->metadata)->toEqual(['before' => ['mode_c_enabled' => true], 'after' => ['mode_c_enabled' => false]]);

    $this->patch(w('/settings/ai/mode-c'), ['mode_c_enabled' => false]);
    expect(AuditLog::where('action', 'agent_policy.changed')->count())->toBe(1);
    $this->patch(w('/settings/ai/mode-c'), ['mode_c_enabled' => true]);
    expect($this->ws->fresh()->setting('mode_c_enabled'))->toBeTrue()->and(AuditLog::where('action', 'agent_policy.changed')->count())->toBe(2);
    $this->patch(w('/settings/ai/mode-c'), [])->assertSessionHasErrors('mode_c_enabled');
});

test('a project Admin turns Mode C off for the project; it logs project.agent_settings_changed; only people may', function () {
    $this->actingAs($this->owner)->patch(w('/projects/WEB'), ['mode_c_enabled' => false])->assertSessionHasNoErrors();

    expect(ProjectAgentSettings::sole()->mode_c_enabled)->toBeFalse();
    $row = AuditLog::where('action', 'project.agent_settings_changed')->sole();
    expect($row->metadata)->toEqual(['mode_c_enabled' => false])->and($row->target_id)->toBe($this->project->id);

    $this->patch(w('/projects/WEB'), ['mode_c_enabled' => false]);
    expect(AuditLog::where('action', 'project.agent_settings_changed')->count())->toBe(1);
    $this->patch(w('/projects/WEB'), ['mode_c_enabled' => true]);
    expect(ProjectAgentSettings::sole()->mode_c_enabled)->toBeTrue()
        ->and(AuditLog::where('action', 'project.agent_settings_changed')->latest('id')->first()->metadata)->toEqual(['mode_c_enabled' => true]);

    $member = addMember($this->project, User::factory()->create(), ProjectRole::Member);
    $this->actingAs($member)->patch(w('/projects/WEB'), ['mode_c_enabled' => false])->assertForbidden();
    [, $token] = makeAgent($this->owner);
    asToken($token)->patchJson('/api/v1/projects/WEB', ['mode_c_enabled' => false])->assertForbidden();
    expect(ProjectAgentSettings::sole()->mode_c_enabled)->toBeTrue();
});

test('the project settings page carries the Mode C state, and saving instructions leaves the switch alone', function () {
    $this->actingAs($this->owner)->get(w('/projects/WEB/settings'))->assertInertia(fn (Assert $page) => $page
        ->where('modeC', ['project' => true, 'workspace' => true, 'provider' => false]));

    $this->patch(w('/projects/WEB'), ['mode_c_enabled' => false]);
    $this->patch(w('/projects/WEB'), ['agent_instructions' => 'Be brief.']);
    expect(ProjectAgentSettings::sole()->mode_c_enabled)->toBeFalse();
});

test('Mode C runs only when the workspace switch, the project switch and a provider are all on', function () {
    expect($this->ai->modeCEnabled($this->project))->toBeFalse();

    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload);
    expect($this->ai->modeCEnabled($this->project))->toBeTrue();

    $this->patch(w('/projects/WEB'), ['mode_c_enabled' => false]);
    expect($this->ai->modeCEnabled($this->project))->toBeFalse();
    $this->patch(w('/projects/WEB'), ['mode_c_enabled' => true]);
    expect($this->ai->modeCEnabled($this->project))->toBeTrue();

    $this->patch(w('/settings/ai/mode-c'), ['mode_c_enabled' => false]);
    expect($this->ai->modeCEnabled($this->project))->toBeFalse();
});

test('the page tells people plainly that Mode C sends issue content to the provider', function () {
    expect(file_get_contents(resource_path('js/Pages/Workspaces/Settings/Ai.tsx')))->toContain('Mode C sends issue content to the chosen provider.');
});
