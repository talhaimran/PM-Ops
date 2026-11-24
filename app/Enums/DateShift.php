<?php

namespace App\Enums;

/** Spec 7.5 (2-R9): what happens to successors when a predecessor's due date moves; a per-user choice. */
enum DateShift: string
{
    case Ask = 'ask';
    case Always = 'always';
    case Never = 'never';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
