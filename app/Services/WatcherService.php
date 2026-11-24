<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\IssueWatcher;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Spec 7.10: who watches an issue. Authorization is the caller's (IssuePolicy::watch / unwatch); this only writes.
 * shortcut: a person removed from the project keeps their row (and shows in the list) until they unwatch; whoever notifies
 * filters by what the watcher can still see. Delete the rows on removal if the list should not show them.
 */
class WatcherService
{
    /** $actor is who asked (kept for the notifications in step 3's next slices); an existing watch keeps its source. */
    public function watch(Issue $issue, User $user, User $actor, string $source = 'manual'): void
    {
        IssueWatcher::insertOrIgnore(['issue_id' => $issue->id, 'user_id' => $user->id, 'source' => $source, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function unwatch(Issue $issue, User $user, User $actor): void
    {
        IssueWatcher::where('issue_id', $issue->id)->where('user_id', $user->id)->delete();
    }

    /** 3-R2: reporter, assignee and commenters start watching, unless they turned auto_watch off or can't see the project. */
    public function autoWatch(Issue $issue, User $user): void
    {
        if ($user->auto_watch && DB::table('project_user')->where('project_id', $issue->project_id)->where('user_id', $user->id)->exists()) {
            $this->watch($issue, $user, $user, 'auto');
        }
    }

    /** @return Collection<int, User> */
    public function watchers(Issue $issue): Collection
    {
        return User::whereIn('id', IssueWatcher::where('issue_id', $issue->id)->select('user_id'))->orderBy('name')->orderBy('id')->get(['id', 'name', 'email']);
    }

    public function isWatching(Issue $issue, User $user): bool
    {
        return IssueWatcher::where('issue_id', $issue->id)->where('user_id', $user->id)->exists();
    }

    /** What IssueResource shows on the single issue. @return array{watchers_count: int, watching: bool, watchers: list<array{id: int, name: string, email: string}>} */
    public function summary(Issue $issue, User $viewer): array
    {
        $watchers = $this->watchers($issue);

        return [
            'watchers_count' => $watchers->count(),
            'watching' => $watchers->contains('id', $viewer->id),
            'watchers' => $watchers->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all(),
        ];
    }

    /** The person to watch or unwatch: $email (a member of the issue's project), or $actor when absent. */
    public function target(Issue $issue, ?string $email, User $actor): User
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || $email === strtolower($actor->email)) {
            return $actor;
        }
        $user = $issue->project->members()->where('email', $email)->first();
        if (! $user) {
            $emails = $issue->project->members()->orderBy('email')->limit(50)->pluck('email')->implode(', ');
            throw ValidationException::withMessages(['user' => "{$email} is not a member of {$issue->project->key}. Add one of: {$emails}."]);
        }

        return $user;
    }
}
