<?php

namespace App\Services\Ai;

use App\Enums\AiProviderType;
use App\Models\Workspace;
use App\Services\AiRequestGuard;
use App\Services\AiSettingsService;
use Illuminate\Http\Client\ConnectionException;

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

        try {
            $response = $adapter->send(AiRequestGuard::http($base)->timeout($timeout), $base, $connection['model'], $this->settings->key($workspace), $system, $messages, $tools);
        } catch (ConnectionException) {
            // Not chained: the original names the URL.
            throw new AiProviderError("The AI provider didn't answer in time. Try again later.");
        }
        if (! $response->successful()) {
            // The body may echo the key or the prompt, so only the status is kept.
            throw new AiProviderError("The AI provider didn't answer (HTTP {$response->status()}). Try again later.");
        }

        $json = $response->json();
        if (! is_array($json)) {
            // E.g. a gateway's HTML page with a 200.
            throw new AiProviderError("The AI provider's reply couldn't be read. Try again later.");
        }

        return $adapter->reply($json);
    }
}
