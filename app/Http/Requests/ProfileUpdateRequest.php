<?php

namespace App\Http\Requests;

use App\Enums\DateShift;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // Spec 7.5 (2-R9): what to do with successors when a due date moves.
            'date_shift' => ['sometimes', Rule::enum(DateShift::class)],
            // Spec 5.1: how My issues is grouped; "focus" is stored as null.
            'my_issues_group' => ['sometimes', 'nullable', Rule::in(DashboardService::GROUPS)],
        ];
    }
}
