<?php

namespace App\Exceptions;

use App\Http\Resources\CommentResource;
use App\Http\Resources\IssueResource;
use App\Models\Comment;
use App\Models\Issue;
use App\Services\ActivityLogger;
use App\Services\IssueService;
use App\Services\UndoService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 7.11: a save carried a version that is no longer current, so nothing was saved. An AuthorizationException, like
 * PlanLimitReached, so MCP tools return its message (the sentence, then the same JSON as REST) as a tool error.
 * Always thrown after the caller's own permission check, so it never tells anything to someone who couldn't write.
 */
class EditConflict extends AuthorizationException
{
    /** @param  array<string, mixed>  $payload  the REST 409 body */
    private function __construct(public readonly array $payload, private readonly Issue|Comment|null $subject = null, private readonly ?string $summary = null)
    {
        parent::__construct($payload['message']."\n\n".json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param  list<array>  $changes  ActivityLogger::changesSince() */
    public static function issue(Issue $issue, int $sent, array $changes): self
    {
        $issue->loadMissing(IssueService::DISPLAY);

        return new self([
            'error' => 'conflict',
            'message' => "{$issue->key} changed since version {$sent}. Read it again and resend.",
            'current_version' => $issue->version,
            'changes' => $changes,
            'issue' => (new IssueResource($issue))->resolve(),
        ], $issue, ActivityLogger::describe($changes, $issue->key));
    }

    public static function comment(Comment $comment, int $sent): self
    {
        return new self([
            'error' => 'conflict',
            'message' => "Comment {$comment->id} changed since version {$sent}. Read it again and resend.",
            'current_version' => $comment->version,
            'comment' => (new CommentResource($comment->loadMissing(['author', 'agent.owner', 'issue.project'])))->resolve(),
        ], $comment);
    }

    /** 1-R11: a bulk update stops whole, naming every stale item. @param  list<self>  $conflicts */
    public static function bulk(array $conflicts): self
    {
        $keys = collect($conflicts)->map(fn (self $c) => $c->subject->key);

        return new self([
            'error' => 'conflict',
            'message' => $keys->implode(', ').' changed since the version you sent. Read '.($keys->count() > 1 ? 'them' : 'it').' again and resend.',
            'conflicts' => collect($conflicts)->map(fn (self $c) => ['key' => $c->subject->key, ...collect($c->payload)->only(['current_version', 'changes'])])->all(),
        ]);
    }

    /**
     * REST: the 409. Web: back with both versions for the conflict dialog and the input kept; an Undo instead says
     * what changed in its toast (spec 5.1).
     */
    public function render(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json($this->payload, 409);
        }
        if ($request->filled('undo_of')) {
            return back()->withErrors(['undo' => "Can't undo: {$this->summary}."]);
        }

        $issue = $this->subject instanceof Comment ? $this->subject->issue : $this->subject;
        $last = collect($this->payload['changes'] ?? [])->last();
        $theirs = $this->subject instanceof Comment
            ? ['body' => $this->subject->body]
            : app(UndoService::class)->forUpdate($this->subject, array_keys($request->except(['version', 'overwrite'])));
        if (array_key_exists('resolution', $theirs)) {
            // The real resolution (forUpdate puts a Duplicate back as Done, for Undo).
            $theirs['resolution'] = $this->subject->resolution?->value;
        }

        return back()->withInput()->withErrors(['version' => $this->payload['message']])->with('conflict', [
            'key' => $issue->key,
            'version' => $this->subject->version,
            'url' => $request->url(),
            // What "Save mine over theirs" resends, so it carries what goes with a field (a Duplicate's original, a reason).
            'yours' => $request->only([...array_keys($theirs), 'duplicate_of', 'reason']),
            'theirs' => $theirs,
            'by' => $this->subject instanceof Comment
                ? ($this->subject->agent ? ActivityLogger::agentLabel($this->subject->agent) : $this->subject->author?->name)
                : ($last['agent'] ?? $last['by'] ?? null),
            'at' => $this->subject instanceof Comment ? $this->subject->updated_at->toIso8601String() : ($last['at'] ?? null),
        ]);
    }
}
