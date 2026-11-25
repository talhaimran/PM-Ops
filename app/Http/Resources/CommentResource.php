<?php

namespace App\Http\Resources;

use App\Models\Comment;
use App\Services\ReactionService;
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
            // 3-R13: [{emoji, count, people}], who reacted by name.
            'reactions' => app(ReactionService::class)->summary($this->resource),
            // 3-R9: what the writer should know after a save (a mention of someone who isn't a member); only when there is something.
            'warnings' => $this->when($this->resource->warnings !== [], fn () => $this->resource->warnings),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
