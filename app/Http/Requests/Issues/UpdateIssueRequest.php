<?php

namespace App\Http\Requests\Issues;

class UpdateIssueRequest extends StoreIssueRequest
{
    /** Spec 7.11 (1-D3): every issue and comment edit sends the version it read. */
    public const VERSION = ['version' => ['required', 'integer', 'min:1']];

    public const VERSION_MESSAGES = ['version.required' => 'Send the version you read (the `version` field of get_issue).'];

    public function rules(): array
    {
        // reason (A-L5): why an agent asks to move to Done, shown to the person who decides.
        return [...parent::rules(), 'title' => ['sometimes', 'required', 'string', 'max:255'], 'reason' => ['nullable', 'string', 'max:200'], ...self::VERSION];
    }

    public function messages(): array
    {
        return [...parent::messages(), ...self::VERSION_MESSAGES];
    }
}
