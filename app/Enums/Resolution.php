<?php

namespace App\Enums;

/** Spec 7.4: how an issue in a Done-category status ended. Only Done counts as completed work. */
enum Resolution: string
{
    case Done = 'done';
    case WontDo = 'wont_do';
    case Duplicate = 'duplicate';
    case CannotReproduce = 'cannot_reproduce';

    public function label(): string
    {
        return match ($this) {
            self::Done => 'Done',
            self::WontDo => "Won't do",
            self::Duplicate => 'Duplicate',
            self::CannotReproduce => 'Cannot reproduce',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
