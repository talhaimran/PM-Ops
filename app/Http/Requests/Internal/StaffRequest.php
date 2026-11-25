<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 3-R18: an internal admin API write. `staff` ({name, email}) is optional and makes the audit actor staff; `reason` is
 * required. Authorized by the service token (InternalApiToken).
 */
class StaffRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'staff' => ['nullable', 'array:name,email'],
            'staff.name' => ['required_with:staff', 'string', 'max:255'],
            'staff.email' => ['required_with:staff', 'email', 'max:255'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
