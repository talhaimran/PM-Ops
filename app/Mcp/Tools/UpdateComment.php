<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Issues\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Mcp\Tools\Concerns\RespondsWithJson;
use App\Services\CommentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_comment')]
#[Description('Replace the text of one of your own comments.')]
#[IsIdempotent]
class UpdateComment extends Tool
{
    use RespondsWithJson;

    public function handle(Request $request, CommentService $comments): Response
    {
        $request->validate(['comment_id' => 'required|integer']);
        $comment = $comments->resolve($request->user(), $request->get('comment_id'));
        Gate::authorize('update', $comment);
        $rules = new UpdateCommentRequest;
        $data = $request->validate($rules->rules(), $rules->messages());
        $comment = $comments->update($comment, $data['body'], (int) $data['version']);

        return $this->result("Updated comment {$comment->id}.", new CommentResource($comment));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'comment_id' => $schema->integer()->description('Comment id from get_issue.')->required(),
            'body' => $schema->string()->description('New Markdown text.')->required(),
            'version' => $schema->integer()->min(1)->description("The comment's version from get_issue. If it changed since, nothing is saved; read it again and resend.")->required(),
        ];
    }
}
