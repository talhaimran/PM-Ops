<?php

namespace App\Enums;

/** 3-R13: the fixed set of comment reactions. */
enum Reaction: string
{
    case ThumbsUp = '👍';
    case Check = '✅';
    case Eyes = '👀';
    case Party = '🎉';
    case Heart = '❤️';
    case Smile = '😄';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
