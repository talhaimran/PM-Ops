<?php

namespace App\Http\Resources;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Comment */
class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            // Spec 7.11: what an edit sends back.
            'version' => $this->version,
            'author' => IssueResource::user($this->author),
            'agent' => $this->whenLoaded('agent', fn () => IssueResource::agent($this->agent)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
