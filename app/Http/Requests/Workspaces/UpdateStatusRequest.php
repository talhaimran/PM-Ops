<?php

namespace App\Http\Requests\Workspaces;

use App\Services\StatusService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Rename and/or recolour. The category is fixed after creation (1-D6). */
class UpdateStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'color' => ['sometimes', 'nullable', Rule::in(StatusService::PALETTE)],
            'category' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'color.in' => 'The colour must be one of: '.implode(', ', StatusService::PALETTE).'.',
            'category.prohibited' => "A status's category can't be changed after it is created. Create a new status in the category you need, then merge into it.",
        ];
    }
}
