<?php

namespace App\Services\Ai;

use App\Enums\AiProviderType;
use App\Models\Workspace;
use App\Services\AiRequestGuard;
use App\Services\AiSettingsService;
use ErrorException;
use Illuminate\Http\Client\ConnectionException;
use TypeError;

/**
 * Step B (B-D1, B-R3): one buffered chat call to the workspace's AI provider, in a neutral shape the run loop uses for
 * every provider. Messages: ['role' => 'user', 'content'], ['role' => 'assistant', 'content' => ?string, 'tool_calls' =>
 * list<{id, name, args}>], ['role' => 'tool', 'tool_call_id', 'name', 'content']. Tools: list<{name, description,
 * parameters (JSON Schema)}>. Every call goes through AiRequestGuard; the key comes only from AiSettingsService::key().
 */
class AiClient
{
    public function __construct(private AiSettingsService $settings) {}

    /** @throws AiProviderError with a user-safe message; a refused endpoint throws the guard's ValidationException */
    public function chat(Workspace $workspace, string $system, array $messages, array $tools, int $timeout): AiReply
    {
        $connection = $this->settings->for($workspace) ?? throw new AiProviderError('Set up an AI provider in Settings → AI first.');
        $provider = $connection['provider'];
        // The optional endpoint replaces the provider's default address (openai_compatible always has one).
        $base = rtrim($connection['endpoint'] ?? match ($provider) {
            AiProviderType::Anthropic => 'https://api.anthropic.com/v1',
            AiProviderType::OpenAi => 'https://api.openai.com/v1',
            AiProviderType::Google => 'https://generativelanguage.googleapis.com/v1beta/openai',
        }, '/');
        $adapter = $provider === AiProviderType::Anthropic ? new AnthropicAdapter : new OpenAiAdapter;

        $sent = 0;
        try {
            $http = AiRequestGuard::http($base)->timeout($timeout)->withRequestMiddleware(function ($request) use (&$sent) {
                $sent = (int) $request->getBody()->getSize();

                return $request;
            });
            $response = $adapter->send($http, $base, $connection['model'], $this->settings->key($workspace), $system, $messages, $tools);
        } catch (ConnectionException) {
            // Not chained: the original names the URL.
            throw new AiProviderError("The AI provider didn't answer in time. Try again later.");
        }
        // The body may echo the key or the prompt, so only the status is kept.
        $status = $response->status();
        if (in_array($status, [401, 403, 404], true)) {
            throw new AiProviderError("The AI provider refused the request (HTTP {$status}). Check the key and model in Settings → AI.");
        }
        if (! $response->successful()) {
            throw new AiProviderError("The AI provider didn't answer (HTTP {$status}). Try again later.");
        }

        // E.g. a gateway's HTML page, or an error object, sent with a 200. Not chained: a parse error can quote the body.
        try {
            $json = $response->json();
            $reply = is_array($json) ? $adapter->reply($json) : null;
        } catch (ErrorException|TypeError) {
            $reply = null;
        }

        if ($reply === null) {
            throw new AiProviderError("The AI provider's reply couldn't be read. Try again later.");
        }
        $in = $reply->tokensIn ?? (int) ceil($sent / 3);
        // shortcut: unknown usage is estimated at 3 bytes per token, use the provider's tokenizer if estimates prove far off
        $out = $reply->tokensOut ?? (int) ceil((strlen((string) $reply->text) + array_sum(array_map(fn ($c) => strlen(json_encode([$c['name'], $c['args']])), $reply->toolCalls))) / 3);

        return new AiReply($reply->text, $reply->toolCalls, $in, $out, $reply->tokensIn === null || $reply->tokensOut === null);
    }
}
