<?php

namespace App\Http\Requests\Issues;

use Illuminate\Foundation\Http\FormRequest;

/** Spec 7.5: link or unlink the issue in the URL (MCP: `from`) and `to`; `type` may be a reverse name. */
class LinkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:30'],
            'to' => ['required', 'string', 'max:30'],
        ];
    }
}
