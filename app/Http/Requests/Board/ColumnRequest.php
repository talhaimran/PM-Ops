<?php

namespace App\Http\Requests\Board;

use App\Enums\StatusCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ColumnRequest extends FormRequest
{
    public function rules(): array
    {
        return self::rulesFor(creating: $this->isMethod('post'));
    }

    public static function rulesFor(bool $creating): array
    {
        return [
            'name' => [...($creating ? ['required'] : ['sometimes', 'required']), 'string', 'max:50'],
            'position' => ['nullable', 'integer', 'min:0'],
            // 1-R4, creating only: an existing library status to map, or the category of the status named after the column.
            ...($creating ? [
                'status' => ['nullable', 'string', 'max:100'],
                'category' => ['nullable', Rule::enum(StatusCategory::class), 'prohibits:status'],
            ] : []),
        ];
    }

    public function messages(): array
    {
        return [
            'category.enum' => 'The category must be one of: '.implode(', ', array_column(StatusCategory::cases(), 'value')).'.',
            'category.prohibits' => 'Send status (an existing status) or category (for a new one), not both: a mapped status keeps its own category.',
        ];
    }
}
