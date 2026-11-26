<?php

namespace App\Http\Requests\Internal;

/** PUT …/entitlements/{key}: one override, with a reason. The value is checked against the key's type (Entitlement::normalize). */
class OverrideRequest extends StaffRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'value' => ['required', fn ($attribute, $value, $fail) => is_scalar($value) || $fail('value must be a number, true/false or text.')]];
    }
}
