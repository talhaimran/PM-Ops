<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Validation\ValidationException;

/**
 * A3 `fields`: keep only the named top-level keys (`key` always stays). Works on the array the resource already built,
 * so it can only drop keys, never add one. Names are checked against what this resource returns.
 * shortcut: the names are checked against the first row, so an empty list never reports an unknown field.
 */
trait SelectsFields
{
    private ?string $fields = null;

    /** @param  string|null  $csv  comma-separated top-level keys */
    public function fields(?string $csv): static
    {
        $this->fields = $csv;

        return $this;
    }

    public static function collectionWith(iterable $items, ?string $csv): AnonymousResourceCollection
    {
        $collection = static::collection($items);
        $collection->collection->each->fields($csv);

        return $collection;
    }

    /** @return list<string> the names in a `fields` value */
    public static function names(?string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $csv)), 'strlen')));
    }

    /** Whether a `fields` value asks for $field (no fields = everything); lets callers skip loading what is dropped. */
    public static function wants(?string $csv, string $field): bool
    {
        return ! ($names = self::names($csv)) || in_array($field, $names, true);
    }

    /** @param  list<string>  $optional  keys this endpoint can return even if they weren't loaded for this call */
    protected function selected(array $data, array $optional = []): array
    {
        $wanted = self::names($this->fields);
        if (! $wanted) {
            return $data;
        }
        $valid = array_values(array_unique([...array_keys(array_filter($data, fn ($value) => ! $value instanceof MissingValue)), ...$optional]));
        if ($unknown = array_diff($wanted, $valid)) {
            throw ValidationException::withMessages(['fields' => 'Unknown field(s): '.implode(', ', $unknown).'. Valid fields: '.implode(', ', $valid).'.']);
        }

        return array_intersect_key($data, array_flip([...$wanted, 'key']));
    }
}
