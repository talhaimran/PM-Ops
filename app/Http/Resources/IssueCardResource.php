<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SelectsFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact issue summary for lists returned to agents (see BoardResource::card). */
class IssueCardResource extends JsonResource
{
    use SelectsFields;

    public function toArray(Request $request): array
    {
        return $this->selected([...BoardResource::card($this->resource), 'sprint' => $this->sprint?->name]);
    }
}
