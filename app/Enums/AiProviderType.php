<?php

namespace App\Enums;

/** Spec 8 (A5): the providers one workspace connection can use; openai_compatible covers Ollama and company gateways. */
enum AiProviderType: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case Google = 'google';
    case OpenAiCompatible = 'openai_compatible';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAi => 'OpenAI',
            self::Google => 'Google',
            self::OpenAiCompatible => 'OpenAI-compatible endpoint',
        };
    }

    /** A local or company model may need no key. */
    public function needsKey(): bool
    {
        return $this !== self::OpenAiCompatible;
    }
}
