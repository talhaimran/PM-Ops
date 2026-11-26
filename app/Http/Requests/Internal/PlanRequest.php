<?php

namespace App\Http\Requests\Internal;

/** PUT …/entitlements: the whole plan as {key: value}; null or '' means no row. Keys and values are checked by Entitlements::replacePlan. */
class PlanRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            ...array_diff_key(parent::rules(), ['reason' => true]),
            'entitlements' => ['present', 'array'],
            'entitlements.*' => ['nullable', fn ($attribute, $value, $fail) => is_scalar($value) || $fail("{$attribute} must be a number, true/false, text or null.")],
        ];
    }
}
