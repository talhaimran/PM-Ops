<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CommentPolicy
{
    public function __construct(private ProjectPolicy $projects) {}

    public function update(User $user, Comment $comment): Response
    {
        $allowed = $this->projects->contribute($user, $comment->issue->project);
        if ($allowed->denied()) {
            return $allowed;
        }

        // An agent's comment is its own to edit, and a person's hand-typed one is theirs: an agent of the same owner can't rewrite it.
        return $comment->isWrittenBy($user)
            ? Response::allow()
            : Response::deny('Only the author can edit a comment.');
    }

    /** 3-R13: reacting (and removing your own) is Write level, like commenting: not a Viewer, not a read-only token. */
    public function react(User $user, Comment $comment): Response
    {
        return $this->projects->contribute($user, $comment->issue->project);
    }

    public function delete(User $user, Comment $comment): Response
    {
        $project = $comment->issue->project;
        $allowed = $this->projects->remove($user, $project);
        if ($allowed->denied()) {
            return $allowed;
        }

        return $comment->user_id === $user->id || $project->roleFor($user)?->canManage()
            ? Response::allow()
            : Response::deny('Only the author or a project admin can delete a comment.');
    }
}
