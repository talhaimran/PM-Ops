<?php

namespace App\Http\Requests\Issues;

class UpdateCommentRequest extends CommentRequest
{
    public function rules(): array
    {
        return [...parent::rules(), ...UpdateIssueRequest::VERSION];
    }

    public function messages(): array
    {
        return UpdateIssueRequest::VERSION_MESSAGES;
    }
}
