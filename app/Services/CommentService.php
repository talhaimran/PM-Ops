<?php

namespace App\Services;

use App\Events\ProjectChanged;
use App\Exceptions\EditConflict;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommentService
{
    public function __construct(private ActivityLogger $activity, private WatcherService $watchers, private MentionService $mentions) {}

    public function add(Issue $issue, User $author, string $body): Comment
    {
        return DB::transaction(function () use ($issue, $author, $body) {
            $comment = $issue->comments()->create(['user_id' => $author->id, 'body' => $body, 'agent_id' => $author->agent()?->id]);
            $this->activity->log($issue, $author, 'commented', ['comment_id' => $comment->id]);
            $this->watchers->autoWatch($issue, $author);
            $comment->warnings = $this->mentions->sync($issue, $comment, null, $body, $author);

            return $comment->setRelation('author', $author);
        });
    }

    /** Spec 7.11 ($actor: who edits, default the author; they get the mention warnings): $version must be the comment's current version, or nothing is saved (EditConflict). A change bumps it. */
    public function update(Comment $comment, string $body, int $version, ?User $actor = null): Comment
    {
        return DB::transaction(function () use ($comment, $body, $version, $actor) {
            // The issue row, then the comment row, held: the check and the write can't interleave with another save or a delete.
            $issueGone = Issue::withTrashed()->whereKey($comment->issue_id)->lockForUpdate()->first()?->trashed() ?? true;
            Comment::withTrashed()->whereKey($comment->id)->lockForUpdate()->first();
            $comment->refresh();
            if ($issueGone || $comment->trashed()) {
                // It or its issue was deleted after the caller loaded it: the same 404 as asking for a deleted comment.
                throw ValidationException::withMessages(['comment' => "Comment {$comment->id} was not found. Get the issue to see its comment ids."])->status(404);
            }
            if ($comment->version !== $version) {
                throw EditConflict::comment($comment, $version);
            }
            $old = $comment->body;
            if ($old !== $body) {
                $comment->increment('version', 1, ['body' => $body]);
                $comment->warnings = $this->mentions->sync($comment->issue()->with('project')->first(), $comment, $old, $body, $actor ?? User::findOrFail($comment->user_id));
                // Edits, deletes and restores aren't in the activity log, so they tell live viewers here.
                ProjectChanged::about($comment->issue_id, 'comment_edited');
            }

            return $comment->loadMissing('author');
        });
    }

    /** Soft delete: hidden everywhere, restorable until issues:purge removes it. */
    public function delete(Comment $comment): void
    {
        $comment->delete();
        ProjectChanged::about($comment->issue_id, 'comment_deleted');
    }

    public function restore(Comment $comment): Comment
    {
        $comment->restore();
        ProjectChanged::about($comment->issue_id, 'comment_restored');

        return $comment;
    }

    /** Find a comment by id; comments in projects the user isn't in are reported as missing. */
    public function resolve(User $user, int|string $id, bool $deleted = false): Comment
    {
        $comment = Comment::with(['issue.project', 'author'])->when($deleted, fn ($q) => $q->onlyTrashed())->find($id);

        if (! $comment?->issue || ! $user->accessibleProjects()->whereKey($comment->issue->project_id)->exists()) {
            throw ValidationException::withMessages([
                'comment' => "Comment {$id} was not found. Get the issue to see its comment ids.",
            ])->status(404);
        }

        return $comment;
    }
}
