<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

/** GET /workspaces: reconciliation, changed since updated_since, paged by the opaque cursor (Pagination). */
class ListWorkspacesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'updated_since' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
