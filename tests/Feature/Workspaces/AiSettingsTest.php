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

    expect(AuditLog::where('action', 'ai_provider.updated')->sole()->metadata)->toEqual(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'endpoint' => null]);
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

    expect(AiProvider::count())->toBe(0)->and($this->ai->for($this->ws))->toBeNull()->and($this->ai->key($this->ws))->toBeNull()
        ->and(AuditLog::where('action', 'ai_provider.removed')->sole()->metadata)->toEqual(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5']);
    $this->delete(w('/settings/ai'))->assertRedirect();
    expect(AuditLog::where('action', 'ai_provider.removed')->count())->toBe(1);
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
    $this->patch(w('/settings/ai/cap'), ['monthly_token_cap' => 5])->assertSessionHasErrors('monthly_token_cap');
    $this->delete(w('/settings/ai'))->assertSessionHasErrors('provider');
    expect(AiProvider::count())->toBe(0)->and(AuditLog::where('action', 'like', 'ai_provider.%')->count())->toBe(0);
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
