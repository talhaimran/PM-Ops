<?php

namespace App\Http\Requests\Issues;

use Illuminate\Foundation\Http\FormRequest;

class WatcherRequest extends FormRequest
{
    public function rules(): array
    {
        // A member's email; absent means yourself.
        return ['user' => ['nullable', 'string', 'max:255']];
    }
}
