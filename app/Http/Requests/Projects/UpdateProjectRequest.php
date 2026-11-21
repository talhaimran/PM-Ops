<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            // A4: notes for agents; web and REST only, and only project Admins who are people (ProjectPolicy::manage).
            'agent_instructions' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }
}
