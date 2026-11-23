<?php

namespace App\Http\Resources;

use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Status */
class StatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category->value,
            // Null means the category's colour (spec 7.4).
            'color' => $this->color,
        ];
    }
}
