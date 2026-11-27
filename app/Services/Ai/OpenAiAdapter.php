<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/** Step B (B-D1): AiClient's neutral shape to and from Chat Completions, for openai, google (its OpenAI-compatible API) and openai_compatible. */
class OpenAiAdapter
{
    public function send(PendingRequest $http, string $base, string $model, #[\SensitiveParameter] ?string $key, string $system, array $messages, array $tools): Response
    {
        $wire = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            $wire[] = match ($m['role']) {
                'user' => ['role' => 'user', 'content' => $m['content']],
                'assistant' => ['role' => 'assistant', 'content' => $m['content'] ?? null, ...(! empty($m['tool_calls']) ? ['tool_calls' => array_map(fn ($c) => [
                    // (object): no arguments must still be "{}".
                    'id' => $c['id'], 'type' => 'function', 'function' => ['name' => $c['name'], 'arguments' => json_encode((object) $c['args'])],
                ], $m['tool_calls'])] : [])],
                'tool' => ['role' => 'tool', 'tool_call_id' => $m['tool_call_id'], 'content' => $m['content']],
            };
        }

        // A local or company model may need no key (openai_compatible).
        return $http->when($key, fn (PendingRequest $h) => $h->withToken($key))->post("{$base}/chat/completions", [
            'model' => $model,
            'messages' => $wire,
            ...($tools ? ['tools' => array_map(fn ($t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters']]], $tools)] : []),
        ]);
    }

    public function reply(array $json): AiReply
    {
        $message = $json['choices'][0]['message'] ?? [];
        $text = $message['content'] ?? null;

        return new AiReply(
            is_string($text) && $text !== '' ? $text : null,
            array_map(fn ($c, $i) => [
                // Some compatible servers leave the id out; the run loop still needs one to pair the result.
                'id' => (string) (($c['id'] ?? '') ?: "call_{$i}"),
                'name' => (string) ($c['function']['name'] ?? ''),
                // Some servers send an object rather than a JSON string; arguments that aren't JSON become [] and the tool's own validation reports what is missing.
                'args' => is_array($raw = $c['function']['arguments'] ?? null) ? $raw : (is_array($args = json_decode((string) $raw, true)) ? $args : []),
            ], $message['tool_calls'] ?? [], array_keys($message['tool_calls'] ?? [])),
            (int) ($json['usage']['prompt_tokens'] ?? 0),
            (int) ($json['usage']['completion_tokens'] ?? 0),
        );
    }
}
