<?php

namespace App\Mcp\Tools;

use App\Enums\Reaction;
use App\Http\Requests\Issues\ReactionRequest;
use App\Http\Resources\CommentResource;
use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\CommentService;
use App\Services\ReactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('remove_reaction')]
#[Description('Take back your own reaction to a comment (you and your agent react separately). Needs Write level; no confirm needed.')]
class RemoveReaction extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, CommentService $comments, ReactionService $reactions): Response
    {
        $request->validate(['comment_id' => 'required|integer|min:1']);
        $comment = $comments->resolve($request->user(), $request->get('comment_id'));
        Gate::authorize('react', $comment);
        $emoji = Reaction::from($request->validate((new ReactionRequest)->rules(), (new ReactionRequest)->messages())['emoji']);
        $reactions->remove($comment, $emoji, $request->user());

        return $this->result("Removed {$emoji->value} from comment {$comment->id}.", new CommentResource($comment->refresh()->load(['author', 'agent.owner'])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'comment_id' => $schema->integer()->description('Comment id (from get_issue).')->required(),
            'emoji' => $schema->string()->enum(Reaction::values())->required(),
        ];
    }
}
