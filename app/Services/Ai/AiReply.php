<?php

namespace App\Services\Ai;

/** One provider answer in the neutral shape: text, tool calls ({id, name, args}) and the tokens it used. */
final readonly class AiReply
{
    /** @param  list<array{id: string, name: string, args: array}>  $toolCalls */
    public function __construct(public ?string $text, public array $toolCalls, public int $tokensIn, public int $tokensOut) {}
}
