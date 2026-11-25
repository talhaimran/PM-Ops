<?php

namespace App\Http\Requests\Internal;

/** 3-R19: who from staff, as whom, why, and for how long (1 to 60 minutes). */
class SupportSessionRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'staff' => ['required', 'array:name,email'],
            'staff.name' => ['required', 'string', 'max:255'],
            'staff.email' => ['required', 'email', 'max:255'],
            'target_user_id' => ['required', 'integer'],
            'minutes' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }
}
