<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * A-L4: `cursor` beside `page`. A cursor is a convenience, not an access token: it grants nothing page= doesn't; every
 * query is still authorized, and the page size in it is capped at the endpoint's max. A cursor is "the next page of this listing": it carries the page and the page size, so
 * changing per_page mid-walk can't misalign it. Every list that uses it is totally ordered (ties break by id).
 * shortcut: offset-based, so a row added or removed between two requests can shift a page by one; switch to keyset
 * cursors per sort if lists ever get large or busy.
 */
class Pagination
{
    /** @return array{0: int, 1: int} [per page, page]; a cursor wins over per_page and page */
    public static function resolve(?string $cursor, int|string|null $perPage, int|string|null $page, int $default = 25, int $max = 100): array
    {
        if ($cursor !== null && $cursor !== '') {
            [$page, $size] = self::decode($cursor);

            return [min($size, $max), $page];
        }

        return [max(1, min((int) ($perPage ?: $default), $max)), max(1, (int) $page)];
    }

    /** The cursor for the page after this one; null on the last page. */
    public static function next(LengthAwarePaginator $page): ?string
    {
        return $page->hasMorePages() ? rtrim(strtr(base64_encode(($page->currentPage() + 1).'.'.$page->perPage()), '+/', '-_'), '=') : null;
    }

    /** A REST list: the usual paginated collection, with `meta.next_cursor`. */
    public static function collection(string $resource, LengthAwarePaginator $page): AnonymousResourceCollection
    {
        return $resource::collection($page)->additional(['meta' => ['next_cursor' => self::next($page)]]);
    }

    /** @return array{0: int, 1: int} */
    private static function decode(string $cursor): array
    {
        $parts = explode('.', (string) base64_decode(strtr($cursor, '-_', '+/'), true));
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1]) || $parts[0] < 1 || $parts[1] < 1) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor. Pass the next_cursor from the previous response unchanged, or use page.']);
        }

        return [(int) $parts[0], (int) $parts[1]];
    }
}
