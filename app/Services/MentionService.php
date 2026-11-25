<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Issue;
use App\Models\Mention;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec 7.10 (3-R9..R11): @email mentions in comments and descriptions. Only mentions new in the text make rows and notifications;
 * a mentioned email that isn't a member of the issue's project is a warning, never a row. People only: an agent can't be mentioned.
 */
class MentionService
{
    public function __construct(private RenderedText $text, private NotificationService $notifications, private WatcherService $watchers) {}

    /**
     * $new's mentions that $old (null on a first save) didn't have. Members are told at once (never batched), auto-watch the issue
     * and get a row (Waiting on you, 3-R10). An agent's mentions are capped per hour (3-R11): over it, nothing is stored or sent.
     * A warning names only the email the writer typed. Call inside the writer's transaction.
     *
     * @return list<array{code: string, message: string, email?: string}>
     */
    public function sync(Issue $issue, ?Comment $comment, ?string $old, string $new, User $actor): array
    {
        $before = $old === null ? [] : $this->text->mentions($old);
        $now = $this->text->mentions($new);
        // An edit that drops a mention takes its row back (no notice either way): the issue leaves their Waiting on you.
        if ($dropped = array_diff($before, $now)) {
            Mention::where(['issue_id' => $issue->id, 'comment_id' => $comment?->id])->whereIn('mentioned_user_id', User::whereIn('email', $dropped)->select('id'))->delete();
        }
        $added = array_values(array_diff($now, $before));
        if (! $added) {
            return [];
        }
        $project = $issue->project;
        $members = $project->members()->whereIn('email', $added)->get()->keyBy(fn (User $u) => strtolower($u->email));
        $agent = $actor->agent();
        $via = ActivityLogger::via($actor);
        $budget = $agent ? max(0, (int) config('pm.agent_mention_limit') - Mention::where('agent_id', $agent->id)->where('created_at', '>=', now()->subHour())->count()) : PHP_INT_MAX;

        $warnings = [];
        $limited = false;
        foreach ($added as $email) {
            $user = $members[$email] ?? null;
            if (! $user) {
                $warnings[] = ['code' => 'mention_not_member', 'email' => $email, 'message' => "{$email} isn't a member of {$project->key}, so they weren't notified and can't see {$issue->key}."];

                continue;
            }
            if ($user->is($actor) || Mention::where(['issue_id' => $issue->id, 'comment_id' => $comment?->id, 'mentioned_user_id' => $user->id])->exists()) {
                continue;
            }
            if ($budget <= 0) {
                $limited = true;

                continue;
            }
            $budget--;
            Mention::create(['issue_id' => $issue->id, 'comment_id' => $comment?->id, 'mentioned_user_id' => $user->id, 'by_user_id' => $actor->id, 'agent_id' => $agent?->id, 'via' => $via]);
            $this->notifications->notify($user, $issue, 'mention', ['action' => 'mention', 'from' => null, 'to' => null], $actor, $agent, $via, batch: false);
            $this->watchers->autoWatch($issue, $user);
        }
        if ($limited) {
            $warnings[] = ['code' => 'mention_rate_limited', 'message' => 'This agent reached its limit of '.config('pm.agent_mention_limit').' mentions an hour. The text was saved; the extra mentions notified nobody.'];
        }

        return $warnings;
    }

    /**
     * 3-R10: which of $issueIds hold a mention of $user they haven't answered, that is, no comment of theirs on the issue since.
     * A mention in a deleted comment doesn't count. shortcut: compared by second, a comment in the same second as the mention answers it.
     *
     * @param  Collection<int, int>  $issueIds
     * @return Collection<int, int>
     */
    public function unanswered(User $user, Collection $issueIds): Collection
    {
        return Mention::where('mentioned_user_id', $user->id)->whereIn('issue_id', $issueIds)
            ->where(fn ($q) => $q->whereNull('comment_id')->orWhereIn('comment_id', Comment::select('id')))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('comments')->whereColumn('comments.issue_id', 'mentions.issue_id')
                ->where('comments.user_id', $user->id)->whereNull('comments.deleted_at')->whereColumn('comments.created_at', '>=', 'mentions.created_at'))
            ->distinct()->pluck('issue_id');
    }
}
