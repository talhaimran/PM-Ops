<?php

namespace App\Services;

use App\Enums\Reaction;
use App\Events\ReactionChanged;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\User;

/**
 * Spec 7.11 (3-R13): reactions on comments. A person and each of their agents react separately. Not in the activity log, no
 * notification, and the comment's version and updated_at don't move. Authorization is the caller's (CommentPolicy::react).
 */
class ReactionService
{
    public function add(Comment $comment, Reaction $emoji, User $actor): void
    {
        $agent = $actor->agent();
        $row = CommentReaction::firstOrCreate(
            ['comment_id' => $comment->id, 'emoji' => $emoji->value, 'reactor' => $agent ? "a{$agent->id}" : "u{$actor->id}"],
            ['user_id' => $actor->id, 'agent_id' => $agent?->id, 'via' => ActivityLogger::via($actor)],
        );
        $row->wasRecentlyCreated && $this->changed($comment);
    }

    public function remove(Comment $comment, Reaction $emoji, User $actor): void
    {
        $agent = $actor->agent();
        $removed = CommentReaction::where(['comment_id' => $comment->id, 'emoji' => $emoji->value, 'reactor' => $agent ? "a{$agent->id}" : "u{$actor->id}"])->delete();
        $removed && $this->changed($comment);
    }

    /**
     * Each emoji in use, in the fixed order, with its count and who reacted ("Sam", "Alex's agent").
     *
     * @return list<array{emoji: string, count: int, people: list<string>, mine: bool}>
     */
    public function summary(Comment $comment): array
    {
        $comment->loadMissing(['reactions.user', 'reactions.agent.owner']);
        // `mine`: the reader (a person, or the agent they act as) is among them; the UI's toggle.
        $viewer = auth()->user();
        $me = $viewer ? ($viewer->agent() ? "a{$viewer->agent()->id}" : "u{$viewer->id}") : null;

        return collect(Reaction::cases())->map(function (Reaction $r) use ($comment, $me) {
            $rows = $comment->reactions->where('emoji', $r->value);

            return ['emoji' => $r->value, 'count' => $rows->count(), 'people' => $rows->map(fn (CommentReaction $c) => $c->agent ? ActivityLogger::agentLabel($c->agent) : $c->user->name)->values()->all(), 'mine' => $rows->contains('reactor', $me)];
        })->filter(fn ($s) => $s['count'] > 0)->values()->all();
    }

    private function changed(Comment $comment): void
    {
        $comment->unsetRelation('reactions');
        event(new ReactionChanged($comment->id, $comment->issue_id, $comment->issue->project_id));
    }
}
