<?php

namespace App\Http\Requests\Workspaces;

use Illuminate\Foundation\Http\FormRequest;

class MergeStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return ['into' => ['required', 'string', 'max:100']];
    }
}
