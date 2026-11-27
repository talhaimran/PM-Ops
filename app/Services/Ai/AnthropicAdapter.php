<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/** Step B (B-D1): AiClient's neutral shape to and from Anthropic's Messages API. */
class AnthropicAdapter
{
    // shortcut: a fixed output cap per call, make it a setting if long drafts get cut off.
    private const MAX_TOKENS = 4096;

    public function send(PendingRequest $http, string $base, string $model, #[\SensitiveParameter] ?string $key, string $system, array $messages, array $tools): Response
    {
        $wire = [];
        foreach ($messages as $m) {
            $turn = match ($m['role']) {
                'user' => ['role' => 'user', 'content' => $m['content']],
                'assistant' => ['role' => 'assistant', 'content' => [
                    ...(filled($m['content'] ?? null) ? [['type' => 'text', 'text' => $m['content']]] : []),
                    // (object): no arguments must still be a JSON object.
                    ...array_map(fn ($c) => ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => (object) $c['args']], $m['tool_calls'] ?? []),
                ]],
                'tool' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => $m['tool_call_id'], 'content' => $m['content']]]],
            };
            // Results of parallel tool calls must all sit in the one user turn after the assistant's.
            $last = array_key_last($wire);
            if ($m['role'] === 'tool' && $last !== null && $wire[$last]['role'] === 'user' && is_array($wire[$last]['content'])) {
                $wire[$last]['content'][] = $turn['content'][0];
            } else {
                $wire[] = $turn;
            }
        }

        return $http->withHeaders(['x-api-key' => (string) $key, 'anthropic-version' => '2023-06-01'])->post("{$base}/messages", [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $system,
            'messages' => $wire,
            ...($tools ? ['tools' => array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters']], $tools)] : []),
        ]);
    }

    /** Null when it isn't a Messages reply; a block missing its fields throws, which AiClient also reads as unreadable. */
    public function reply(array $json): ?AiReply
    {
        if (! is_array($json['content'] ?? null)) {
            return null;
        }
        $blocks = collect($json['content']);
        $text = $blocks->where('type', 'text')->pluck('text')->implode("\n");

        return new AiReply(
            $text !== '' ? $text : null,
            $blocks->where('type', 'tool_use')->map(fn ($b) => ['id' => (string) $b['id'], 'name' => (string) $b['name'], 'args' => (array) ($b['input'] ?? [])])->values()->all(),
            AiReply::count($json['usage']['input_tokens'] ?? null),
            AiReply::count($json['usage']['output_tokens'] ?? null),
        );
    }
}
