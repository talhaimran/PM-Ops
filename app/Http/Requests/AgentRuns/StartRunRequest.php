<?php

namespace App\Http\Requests\AgentRuns;

use Illuminate\Foundation\Http\FormRequest;

/** Step B: start a run on an issue with one of your own agents, in a role or with a typed instruction (or both). */
class StartRunRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'agent' => ['required', 'integer'],
            'role' => ['nullable', 'integer'],
            'instruction' => ['nullable', 'string', 'max:4000', 'required_without:role'],
        ];
    }

    public function messages(): array
    {
        return ['instruction.required_without' => 'Pick a role or type an instruction.'];
    }
}
