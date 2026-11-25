<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Reaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Issues\CommentRequest;
use App\Http\Requests\Issues\ReactionRequest;
use App\Http\Requests\Issues\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Services\CommentService;
use App\Services\IssueService;
use App\Services\ReactionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    public function __construct(private IssueService $issues, private CommentService $comments, private ReactionService $reactions) {}

    /** Add a Markdown comment to an issue. */
    public function store(CommentRequest $request, string $key): CommentResource
    {
        $issue = $this->issues->resolve($request->user(), $key);
        Gate::authorize('comment', $issue);

        return new CommentResource($this->comments->add($issue, $request->user(), $request->validated('body')));
    }

    /**
     * Edit your own comment. Send the `version` you read (get the issue): if the comment changed since, nothing is saved
     * and you get 409 `{"error": "conflict", "current_version": …, "comment": {…}}`.
     */
    public function update(UpdateCommentRequest $request, int $comment): CommentResource
    {
        $comment = $this->comments->resolve($request->user(), $comment);
        Gate::authorize('update', $comment);

        return new CommentResource($this->comments->update($comment, $request->validated('body'), (int) $request->validated('version'), $request->user()));
    }

    /** React to a comment with 👍 ✅ 👀 🎉 ❤️ or 😄 (`emoji`), as you or as your agent; repeating it does nothing. Write level; nobody is notified and the comment's version doesn't change. */
    public function react(ReactionRequest $request, int $comment): CommentResource
    {
        $comment = $this->comments->resolve($request->user(), $comment);
        Gate::authorize('react', $comment);
        $this->reactions->add($comment, Reaction::from($request->validated('emoji')), $request->user());

        return new CommentResource($comment->refresh()->load(['author', 'agent.owner']));
    }

    /** Take back your own reaction (`emoji`). Write level. */
    public function unreact(ReactionRequest $request, int $comment): CommentResource
    {
        $comment = $this->comments->resolve($request->user(), $comment);
        Gate::authorize('react', $comment);
        $this->reactions->remove($comment, Reaction::from($request->validated('emoji')), $request->user());

        return new CommentResource($comment->refresh()->load(['author', 'agent.owner']));
    }

    /** Delete a comment (author or project admin). */
    public function destroy(Request $request, int $comment): Response
    {
        $comment = $this->comments->resolve($request->user(), $comment);
        Gate::authorize('delete', $comment);
        $this->comments->delete($comment);

        return response()->noContent();
    }
}
