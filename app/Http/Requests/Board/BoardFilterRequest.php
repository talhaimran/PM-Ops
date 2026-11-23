<?php

namespace App\Http\Requests\Board;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\Resolution;
use App\Enums\StatusCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoardFilterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'assignee' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(IssueType::class)],
            'priority' => ['nullable', Rule::enum(IssuePriority::class)],
            'label' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', Rule::enum(StatusCategory::class)],
            'resolution' => ['nullable', Rule::enum(Resolution::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'sprint' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.enum' => 'The type filter must be one of: '.implode(', ', IssueType::values()).'.',
            'priority.enum' => 'The priority filter must be one of: '.implode(', ', IssuePriority::values()).'.',
            'category.enum' => 'The category filter must be one of: '.implode(', ', StatusCategory::values()).'.',
            'resolution.enum' => 'The resolution filter must be one of: '.implode(', ', Resolution::values()).'.',
        ];
    }
}
