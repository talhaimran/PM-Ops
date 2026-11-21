<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * A-L4: `cursor` beside `page`. A cursor is a convenience, not an access token: it grants nothing page= doesn't; every
 * query is still authorized, and a page size over the endpoint's max is rejected. Pages run 1 to 10,000. A cursor is "the next page of this listing": it carries the page and the page size, so
 * changing per_page mid-walk can't misalign it. Every list that uses it is totally ordered (ties break by id).
 * shortcut: offset-based, so a row added or removed between two requests can shift a page by one; switch to keyset
 * cursors per sort if lists ever get large or busy.
 */
class Pagination
{
    public const MAX_PAGE = 10000;

    /**
     * @return array{0: int, 1: int} [per page, page]; a cursor wins over per_page and page. A page outside 1..MAX_PAGE
     *                               (0 or null means not given) or a forged cursor is a 422; per_page is clamped.
     */
    public static function resolve(?string $cursor, int|string|null $perPage, int|string|null $page, int $default = 25, int $max = 100): array
    {
        if ($cursor !== null && $cursor !== '') {
            return array_reverse(self::decode($cursor, $max));
        }
        $number = filter_var($page, FILTER_VALIDATE_INT);
        if ($number === false && $page !== null && $page !== '' || $number !== false && $number !== 0 && ($number < 1 || $number > self::MAX_PAGE)) {
            throw ValidationException::withMessages(['page' => 'page must be between 1 and '.self::MAX_PAGE.'.']);
        }

        return [max(1, min((int) ($perPage ?: $default), $max)), max(1, (int) $number)];
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
    private static function decode(string $cursor, int $max): array
    {
        $parts = explode('.', (string) base64_decode(strtr($cursor, '-_', '+/'), true));
        // Lengths first: an issued cursor never exceeds these, so anything longer is forged and must not reach (int).
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1]) || strlen($parts[0]) > 5 || strlen($parts[1]) > 3
            || $parts[0] < 1 || $parts[0] > self::MAX_PAGE || $parts[1] < 1 || $parts[1] > $max) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor. Pass the next_cursor from the previous response unchanged, or use page.']);
        }

        return [(int) $parts[0], (int) $parts[1]];
    }
}
