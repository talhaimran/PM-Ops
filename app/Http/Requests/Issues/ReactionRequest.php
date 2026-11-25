<?php

namespace App\Http\Requests\Issues;

use App\Enums\Reaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReactionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['emoji' => ['required', 'string', Rule::enum(Reaction::class)]];
    }

    public function messages(): array
    {
        return ['emoji.enum' => 'Reactions are one of '.implode(' ', Reaction::values()).'.'];
    }
}
