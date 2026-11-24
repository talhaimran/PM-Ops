<?php

namespace App\Enums;

use Illuminate\Validation\ValidationException;

/** Spec 7.5: link types, stored in the forward direction; the reverse names are accepted as input and shown on the target. */
enum LinkType: string
{
    case Blocks = 'blocks';
    case Precedes = 'precedes';
    case RelatesTo = 'relates to';
    case Duplicates = 'duplicates';
    case Clones = 'clones';

    public function reverse(): string
    {
        return match ($this) {
            self::Blocks => 'is blocked by',
            self::Precedes => 'follows',
            self::RelatesTo => 'relates to',
            self::Duplicates => 'is duplicated by',
            self::Clones => 'is cloned by',
        };
    }

    /** No loops for blocks and precedes (spec 7.5). */
    public function forbidsLoops(): bool
    {
        return $this === self::Blocks || $this === self::Precedes;
    }

    /** Every name, forward then reverse per type, in display order. @return list<string> */
    public static function names(): array
    {
        return collect(self::cases())->flatMap(fn (self $t) => array_unique([$t->value, $t->reverse()]))->values()->all();
    }

    /**
     * A name as sent ("blocks", "Is_Blocked_By"): the type, and whether it was the reverse name.
     *
     * @return array{0: self, 1: bool}
     */
    public static function parse(string $name): array
    {
        $normal = strtolower(trim(preg_replace('/[\s_]+/', ' ', $name)));
        foreach (self::cases() as $type) {
            if ($normal === $type->value) {
                return [$type, false];
            }
            if ($normal === $type->reverse()) {
                return [$type, true];
            }
        }

        throw ValidationException::withMessages(['type' => "Unknown link type \"{$name}\". Use one of: ".implode(', ', self::names()).'.']);
    }
}
