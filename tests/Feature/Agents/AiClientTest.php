<?php

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiProviderError;
use App\Services\Ai\AiReply;
use App\Services\AiRequestGuard;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

// Step B (B-D1, B-R3): the two provider adapters behind AiClient, always sent through AiRequestGuard. DNS is faked
// through the guard's seam and HTTP through Http::fake; no test touches the network.

beforeEach(function () {
    $this->ws = makeWorkspace(User::factory()->create(), 'acme');
    $this->parameters = ['type' => 'object', 'properties' => ['key' => ['type' => 'string']], 'required' => ['key']];
    $this->tools = [['name' => 'read_issue', 'description' => 'Read one issue', 'parameters' => $this->parameters]];
    // A turn in the neutral shape: the model asked for a tool and got its result back.
    $this->messages = [
        ['role' => 'user', 'content' => 'Triage WEB-1'],
        ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'name' => 'read_issue', 'args' => ['key' => 'WEB-1']]]],
        ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'read_issue', 'content' => '{"key":"WEB-1"}'],
    ];
    AiRequestGuard::resolveUsing(fn () => ['93.184.216.34']);
});
afterEach(fn () => AiRequestGuard::resolveUsing(null));

function connectAi(Workspace $ws, AiProviderType $provider, ?string $endpoint = null): void
{
    AiProvider::updateOrCreate(['workspace_id' => $ws->id], ['provider' => $provider, 'model' => 'test-model', 'endpoint' => $endpoint, 'key' => 'sk-SECRET-123']);
}

test('sends each provider format through the guard: Anthropic', function () {
    connectAi($this->ws, AiProviderType::Anthropic);
    Http::fake(['https://api.anthropic.com/v1/messages' => Http::sequence()
        ->push(['content' => [['type' => 'text', 'text' => 'Reading it.'], ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'read_issue', 'input' => ['key' => 'WEB-2']]],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30]])
        ->push(['content' => [['type' => 'text', 'text' => 'Done.']], 'usage' => ['input_tokens' => 200, 'output_tokens' => 10]])]);
    $ai = app(AiClient::class);

    expect($ai->chat($this->ws, 'You triage.', $this->messages, $this->tools, 30))
        ->toEqual(new AiReply('Reading it.', [['id' => 'toolu_2', 'name' => 'read_issue', 'args' => ['key' => 'WEB-2']]], 120, 30))
        ->and($ai->chat($this->ws, 'You triage.', $this->messages, [], 30))->toEqual(new AiReply('Done.', [], 200, 10));

    [[$request], [$second]] = Http::recorded()->all();
    expect($request->url())->toBe('https://api.anthropic.com/v1/messages')
        ->and($request->header('x-api-key'))->toBe(['sk-SECRET-123'])
        ->and($request->header('anthropic-version'))->toBe(['2023-06-01'])
        ->and($request->hasHeader('Authorization'))->toBeFalse()
        ->and($request['model'])->toBe('test-model')
        ->and($request['system'])->toBe('You triage.')
        ->and($request['tools'])->toBe([['name' => 'read_issue', 'description' => 'Read one issue', 'input_schema' => $this->parameters]])
        // As sent on the wire: the PHP payload holds stdClass for tool input.
        ->and(json_decode($request->body(), true)['messages'])->toBe([
            ['role' => 'user', 'content' => 'Triage WEB-1'],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'call_1', 'name' => 'read_issue', 'input' => ['key' => 'WEB-1']]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => '{"key":"WEB-1"}']]],
        ])
        ->and($second->data())->not->toHaveKey('tools');
});

test('sends each provider format through the guard: Chat Completions', function (AiProviderType $provider, ?string $endpoint, string $url) {
    connectAi($this->ws, $provider, $endpoint);
    Http::fake([$url => Http::sequence()
        ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null,
            'tool_calls' => [['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'read_issue', 'arguments' => '{"key":"WEB-2"}']]]]]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30]])
        ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Done.']]], 'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 10]])]);
    $ai = app(AiClient::class);

    expect($ai->chat($this->ws, 'You triage.', $this->messages, $this->tools, 30))
        ->toEqual(new AiReply(null, [['id' => 'call_2', 'name' => 'read_issue', 'args' => ['key' => 'WEB-2']]], 120, 30))
        ->and($ai->chat($this->ws, 'You triage.', $this->messages, [], 30))->toEqual(new AiReply('Done.', [], 200, 10));

    [[$request], [$second]] = Http::recorded()->all();
    expect($request->url())->toBe($url)
        ->and($request->header('Authorization'))->toBe(['Bearer sk-SECRET-123'])
        ->and($request->hasHeader('x-api-key'))->toBeFalse()
        ->and($request['model'])->toBe('test-model')
        ->and($request['tools'])->toBe([['type' => 'function', 'function' => ['name' => 'read_issue', 'description' => 'Read one issue', 'parameters' => $this->parameters]]])
        ->and($request['messages'])->toBe([
            ['role' => 'system', 'content' => 'You triage.'],
            ['role' => 'user', 'content' => 'Triage WEB-1'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'read_issue', 'arguments' => '{"key":"WEB-1"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"key":"WEB-1"}'],
        ])
        ->and($second->data())->not->toHaveKey('tools');
})->with([
    'openai' => [AiProviderType::OpenAi, null, 'https://api.openai.com/v1/chat/completions'],
    'google' => [AiProviderType::Google, null, 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'],
    'openai_compatible' => [AiProviderType::OpenAiCompatible, 'https://gateway.example.com/v1', 'https://gateway.example.com/v1/chat/completions'],
    'openai with its optional endpoint' => [AiProviderType::OpenAi, 'https://proxy.example.com/openai/', 'https://proxy.example.com/openai/chat/completions'],
]);

test('refuses a private endpoint', function () {
    connectAi($this->ws, AiProviderType::OpenAiCompatible, 'https://gateway.example.com/v1');
    AiRequestGuard::resolveUsing(fn () => ['10.0.0.5']);
    Http::fake();

    expect(fn () => app(AiClient::class)->chat($this->ws, 'You triage.', $this->messages, $this->tools, 30))
        ->toThrow(ValidationException::class, 'private');
    Http::assertNothingSent();
});

test('refuses with a plain message when no provider is set up', function () {
    Http::fake();

    expect(fn () => app(AiClient::class)->chat($this->ws, 'You triage.', $this->messages, $this->tools, 30))
        ->toThrow(AiProviderError::class, 'Set up an AI provider in Settings → AI first.');
    Http::assertNothingSent();
});

test('fails safely', function (int|string $status, string $message) {
    connectAi($this->ws, AiProviderType::OpenAi);
    Http::fake(['*' => match ($status) {
        'offline' => Http::failedConnection('cURL error 28 for https://api.openai.com/v1 sk-SECRET-123'),
        'not json' => Http::response('<html>Sign in again, sk-SECRET-123 Incorrect API key</html>', 200),
        // A gateway reporting an error with a 200.
        'error with 200' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-SECRET-123']], 200),
        default => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-SECRET-123']], $status),
    }]);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
        $logged[] = $m->message.' '.json_encode(array_map(fn ($v) => is_object($v) ? (string) $v : $v, $m->context));
    });

    try {
        app(AiClient::class)->chat($this->ws, 'You triage.', $this->messages, $this->tools, 30);
        $this->fail('No error');
    } catch (AiProviderError $e) {
        report($e);
    }

    expect($e->getMessage())->toBe($message)
        ->and($e->getPrevious())->toBeNull()
        ->and($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))->not->toContain('sk-SECRET-123')->not->toContain('Incorrect API key');
})->with([
    [429, "The AI provider didn't answer (HTTP 429). Try again later."],
    [500, "The AI provider didn't answer (HTTP 500). Try again later."],
    ['offline', "The AI provider didn't answer in time. Try again later."],
    ['not json', "The AI provider's reply couldn't be read. Try again later."],
    ['error with 200', "The AI provider's reply couldn't be read. Try again later."],
    [401, 'The AI provider refused the request (HTTP 401). Check the key and model in Settings → AI.'],
    [403, 'The AI provider refused the request (HTTP 403). Check the key and model in Settings → AI.'],
    [404, 'The AI provider refused the request (HTTP 404). Check the key and model in Settings → AI.'],
]);

test('an Anthropic reply that can\'t be read fails safely', function (array $body) {
    connectAi($this->ws, AiProviderType::Anthropic);
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => app(AiClient::class)->chat($this->ws, 'You triage.', $this->messages, $this->tools, 30))
        ->toThrow(AiProviderError::class, "The AI provider's reply couldn't be read. Try again later.");
})->with([
    'no content' => [['error' => ['message' => 'sk-SECRET-123']]],
    'a tool_use without id' => [['content' => [['type' => 'tool_use', 'name' => 'read_issue', 'input' => []]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]],
]);

test('reads tool arguments sent as an object, or not as JSON at all', function () {
    connectAi($this->ws, AiProviderType::OpenAiCompatible, 'https://gateway.example.com/v1');
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '', 'tool_calls' => [
        ['function' => ['name' => 'read_issue', 'arguments' => ['key' => 'WEB-3']]],
        ['id' => 'x', 'function' => ['name' => 'search_issues', 'arguments' => 'not json']],
    ]]]]])]);

    expect(app(AiClient::class)->chat($this->ws, 'You triage.', $this->messages, $this->tools, 30)->toolCalls)->toEqual([
        ['id' => 'call_0', 'name' => 'read_issue', 'args' => ['key' => 'WEB-3']],
        ['id' => 'x', 'name' => 'search_issues', 'args' => []],
    ]);
});

// Unknown usage is never counted as zero: it is estimated at ceil(bytes / 3) and the reply says so.
test('estimates the tokens a provider did not report', function (array $usage, ?int $in, ?int $out) {
    connectAi($this->ws, AiProviderType::OpenAi);
    $message = ['role' => 'assistant', 'content' => 'Hello there', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read_issue', 'arguments' => '{"key":"WEB-2"}']]]];
    Http::fake(['*' => Http::response(['choices' => [['message' => $message]], ...($usage ? ['usage' => $usage] : [])])]);

    $reply = app(AiClient::class)->chat($this->ws, 'You triage.', $this->messages, $this->tools, 30);

    $sent = strlen(Http::recorded()->first()[0]->body());
    $outBytes = strlen('Hello there') + strlen(json_encode(['read_issue', ['key' => 'WEB-2']]));
    expect($reply->usageEstimated)->toBeTrue()
        ->and($reply->tokensIn)->toBe($in ?? (int) ceil($sent / 3))
        ->and($reply->tokensOut)->toBe($out ?? (int) ceil($outBytes / 3));
})->with([
    'no usage' => [[], null, null],
    'only the input' => [['prompt_tokens' => 120], 120, null],
    'junk' => [['prompt_tokens' => 'lots', 'completion_tokens' => -5], null, null],
]);

test('estimates an Anthropic reply without usage', function () {
    connectAi($this->ws, AiProviderType::Anthropic);
    Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'Done.']]])]);

    $reply = app(AiClient::class)->chat($this->ws, 'You triage.', $this->messages, $this->tools, 30);

    expect($reply->usageEstimated)->toBeTrue()
        ->and($reply->tokensIn)->toBe((int) ceil(strlen(Http::recorded()->first()[0]->body()) / 3))
        ->and($reply->tokensOut)->toBe((int) ceil(strlen('Done.') / 3));
});

test('reads a real-shaped Google reply exactly', function () {
    connectAi($this->ws, AiProviderType::Google, 'https://generativelanguage.googleapis.com/v1beta/openai');
    Http::fake(['*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => null,
        'tool_calls' => [['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'read_issue', 'arguments' => '{"key":"WEB-2"}']]]]]],
        'usage' => ['prompt_tokens' => 55, 'completion_tokens' => 7, 'total_tokens' => 62]])]);

    expect(app(AiClient::class)->chat($this->ws, 'You triage.', $this->messages, $this->tools, 30))
        ->toEqual(new AiReply(null, [['id' => 'call_9', 'name' => 'read_issue', 'args' => ['key' => 'WEB-2']]], 55, 7));
});
