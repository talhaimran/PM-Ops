<?php

namespace App\Http\Requests\Board;

use App\Enums\Resolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveIssueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:100'],
            'resolution' => ['nullable', Rule::enum(Resolution::class)],
            'duplicate_of' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'integer', 'min:0', 'prohibits:after'],
            'after' => ['nullable', 'string', 'max:30'],
            // A-L5: why an agent asks to move to Done, shown to the person who decides.
            'reason' => ['nullable', 'string', 'max:200'],
            // 2-D4: move although the issue is blocked or a predecessor isn't resolved.
            'confirm' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'position.prohibits' => 'Send either position or after, not both.',
            'resolution.enum' => 'The resolution must be one of: '.implode(', ', Resolution::values()).'.',
        ];
    }
}
