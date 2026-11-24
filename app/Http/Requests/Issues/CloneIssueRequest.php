<?php

namespace App\Http\Requests\Issues;

use Illuminate\Foundation\Http\FormRequest;

/** Spec 7.9: clone the issue in the URL (MCP: `key`); sub-tasks and links are opt-in. */
class CloneIssueRequest extends FormRequest
{
    public function rules(): array
    {
        return ['subtasks' => ['sometimes', 'boolean'], 'links' => ['sometimes', 'boolean']];
    }
}
