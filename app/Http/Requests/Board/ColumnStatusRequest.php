<?php

namespace App\Http\Requests\Board;

use App\Enums\StatusCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add a status to a column: an existing one by name, or a new one with a category. */
class ColumnStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required_without:name', 'nullable', 'string', 'max:100', 'prohibits:name'],
            'name' => ['required_without:status', 'nullable', 'string', 'max:100'],
            'category' => ['required_with:name', 'nullable', Rule::enum(StatusCategory::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'category.enum' => 'The category must be one of: '.implode(', ', StatusCategory::values()).'.',
            'status.prohibits' => 'Send status (an existing one) or name and category (a new one), not both.',
        ];
    }
}
