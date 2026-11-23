<?php

namespace App\Enums;

/** Spec 7.4: every status has exactly one, fixed category. "Done" anywhere in the app means this Done. */
enum StatusCategory: string
{
    case ToDo = 'to_do';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::ToDo => 'To do',
            self::InProgress => 'In progress',
            self::Done => 'Done',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
