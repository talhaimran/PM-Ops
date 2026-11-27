<?php

namespace App\Services\Ai;

/** One provider answer in the neutral shape: text, tool calls ({id, name, args}) and the tokens it used. Adapters leave an unreported count null; AiClient fills it in. */
final readonly class AiReply
{
    /** @param  list<array{id: string, name: string, args: array}>  $toolCalls */
    public function __construct(public ?string $text, public array $toolCalls, public ?int $tokensIn, public ?int $tokensOut, public bool $usageEstimated = false) {}

    /** A reported count: an int or numeric string of 0 or more; anything else is unknown. */
    public static function count(mixed $value): ?int
    {
        return (is_int($value) || is_string($value)) && is_numeric($value) && (int) $value >= 0 && (float) $value == (int) $value ? (int) $value : null;
    }
}
