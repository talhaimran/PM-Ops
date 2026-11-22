<?php

use App\Enums\AgentLevel;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AiSettingsService;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Spec 8 (A5): the AI key is encrypted at rest, never returned by any API or page, never logged, redacted from errors.

const AI_SECRET = 'sk-test-SECRET';

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->project = makeProject($this->owner, 'WEB');
    $this->payload = ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'endpoint' => '', 'api_key' => AI_SECRET];
    $this->log = storage_path('logs/laravel.log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->log]);
    @unlink($this->log);
});

afterEach(fn () => @unlink($this->log));

function aiLog(): string
{
    return is_file(storage_path('logs/laravel.log')) ? file_get_contents(storage_path('logs/laravel.log')) : '';
}

test('after saving, the secret is in no Inertia prop, no page, no flash, and the column is not plaintext', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload)->assertSessionHasNoErrors();

    foreach (['/settings/ai', '/dashboard', '/projects/WEB', '/projects/WEB/settings', '/settings/security', '/settings/audit-log'] as $path) {
        expect($this->get(w($path))->assertOk()->getContent())->not->toContain(AI_SECRET);
    }
    expect(json_encode(session()->all()))->not->toContain(AI_SECRET);

    $raw = DB::table('ai_providers')->value('key');
    expect($raw)->not->toContain(AI_SECRET)->and($raw)->not->toBe(AI_SECRET)
        ->and(AiProvider::sole()->key)->toBe(AI_SECRET)
        ->and(app(AiSettingsService::class)->key($this->ws))->toBe(AI_SECRET);
});

test('the model never serialises its key', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload);
    $row = AiProvider::sole();

    expect($row->toArray())->not->toHaveKey('key')
        ->and($row->toJson())->not->toContain(AI_SECRET)
        ->and(json_encode($row))->not->toContain(AI_SECRET)
        ->and(var_export($row->attributesToArray(), true))->not->toContain(AI_SECRET);
});

test('no audit row holds the secret, whichever settings change', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload);
    $this->patch(w('/settings/ai'), [...$this->payload, 'api_key' => 'sk-test-SECOND']);
    $this->patch(w('/settings/ai/cap'), ['monthly_token_cap' => 10]);
    $this->patch(w('/settings/ai/mode-c'), ['mode_c_enabled' => false]);
    $this->delete(w('/settings/ai'));

    $rows = json_encode(AuditLog::all()->map->getAttributes());
    expect(AuditLog::where('action', 'like', 'ai_provider.%')->count())->toBeGreaterThan(3)
        ->and($rows)->not->toContain(AI_SECRET)->not->toContain('sk-test-SECOND');
});

test('a rejected save never flashes the key back, writes nothing to the log, and no error message echoes it', function () {
    $response = $this->actingAs($this->owner)->from(w('/settings/ai'))->patch(w('/settings/ai'), [...$this->payload, 'model' => '']);

    $response->assertSessionHasErrors('model')->assertSessionMissing('_old_input.api_key');
    expect(json_encode(session()->all()))->not->toContain(AI_SECRET)->and(aiLog())->not->toContain(AI_SECRET);

    $tooLong = AI_SECRET.str_repeat('x', 600);
    $this->from(w('/settings/ai'))->patch(w('/settings/ai'), [...$this->payload, 'api_key' => $tooLong])->assertSessionHasErrors('api_key');
    expect(json_encode(session('errors')->getBag('default')))->not->toContain(AI_SECRET)->and(json_encode(session()->all()))->not->toContain(AI_SECRET);
});

test('an unexpected failure while saving reports without the key', function () {
    $this->mock(AuditLogger::class)->shouldReceive('log')->andThrow(new RuntimeException('audit store is down'));

    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload)->assertStatus(500);

    expect(aiLog())->toContain('audit store is down')->not->toContain(AI_SECRET);
});

test('the key parameters are marked sensitive so a stack trace cannot print them', function () {
    foreach ([[AiSettingsService::class, 'update', 'data'], [AiSettingsService::class, 'encrypted', 'key']] as [$class, $method, $param]) {
        $parameter = collect((new ReflectionMethod($class, $method))->getParameters())->firstWhere(fn ($p) => $p->getName() === $param);
        expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }
});

test('no REST or MCP response carries the key, and no API route reaches AI settings', function () {
    $this->actingAs($this->owner)->patch(w('/settings/ai'), $this->payload);
    $env = AiProvider::sole();
    [, $token] = makeAgent($this->owner, AgentLevel::Delete);

    foreach (['me', 'projects', 'projects/WEB', 'projects/WEB/summary', 'me/updates'] as $path) {
        expect(asToken($token)->getJson("/api/v1/{$path}")->getContent())->not->toContain(AI_SECRET)->not->toContain($env->getRawOriginal('key'));
    }
    foreach (['whoami' => [], 'get_project' => ['project' => 'WEB']] as $tool => $args) {
        expect(json_encode(mcpJson($token, $tool, $args)))->not->toContain(AI_SECRET);
    }

    $uris = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/') || $r->uri() === 'mcp')->map->uri();
    expect($uris->filter(fn ($u) => preg_match('#(^|/)(ai|settings)(/|$)#', $u))->all())->toBe([]);
});

test('API tokens and agents cannot reach Settings, AI over the web routes', function () {
    [, $token] = makeAgent($this->owner, AgentLevel::Delete);

    asToken($token)->patchJson(w('/settings/ai'), $this->payload)->assertUnauthorized();
    asToken($token)->getJson(w('/settings/ai'))->assertUnauthorized();
    asToken($token)->patchJson(w('/settings/ai/mode-c'), ['mode_c_enabled' => false])->assertUnauthorized();
    asToken($token)->deleteJson(w('/settings/ai'))->assertUnauthorized();

    expect(AiProvider::count())->toBe(0)->and($this->ws->fresh()->setting('mode_c_enabled'))->toBeTrue();
});

test('the key set on the server is never in a page either', function () {
    config(['pm.ai' => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'endpoint' => null, 'key' => 'sk-env-SECRET']]);

    foreach (['/settings/ai', '/dashboard', '/projects/WEB/settings'] as $path) {
        expect($this->actingAs($this->owner)->get(w($path))->assertOk()->getContent())->not->toContain('sk-env-SECRET');
    }
});
