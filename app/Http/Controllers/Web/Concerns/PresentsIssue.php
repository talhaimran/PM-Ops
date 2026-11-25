<?php

namespace App\Http\Controllers\Web\Concerns;

use App\Http\Resources\IssueResource;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Services\ProjectService;
use App\Services\RenderedText;
use Illuminate\Http\Request;

/** What the issue view needs, shared by the full page (/issues/{KEY}) and the peek drawer (?issue={KEY}). */
trait PresentsIssue
{
    /** @return array{issue: array<string, mixed>, options: array<string, mixed>, can: array<string, bool>} */
    protected function issueView(Issue $issue, User $user): array
    {
        $issue = app(IssueService::class)->loadFull($issue, $user);
        $data = (new IssueResource($issue))->resolve();
        $text = app(RenderedText::class);
        $data['description_html'] = $text->html($issue->description, $user, $issue->project);
        $data['comments'] = $issue->comments->values()->map(fn ($comment, $i) => [
            ...$data['comments'][$i],
            'body_html' => $text->html($comment->body, $user, $issue->project),
            'can_edit' => $comment->isWrittenBy($user),
        ])->all();
        $data['pending_approvals'] = $issue->pendingApprovals->map(fn ($approval, $i) => [
            ...$data['pending_approvals'][$i],
            'can_decide' => $user->can('decide', $approval),
        ])->all();

        return [
            'issue' => $data,
            'options' => app(ProjectService::class)->issueOptions($issue->project),
            'can' => [
                'contribute' => $user->can('contribute', $issue->project),
                'manage' => $user->can('manage', $issue->project),
            ],
        ];
    }

    /**
     * 3-R9: what a save tells its writer about a mentioned email that isn't a member: a project admin is asked "Invite Sam to this
     * project?" (flash mention_invite), anyone else is told they can't see the issue (the toast). Merge into the redirect's flash.
     *
     * @param  list<array{code: string, email?: string}>  $warnings
     * @return array<string, mixed>
     */
    protected function mentionFlash(array $warnings, Project $project): array
    {
        $emails = collect($warnings)->where('code', 'mention_not_member')->pluck('email')->all();
        if (! $emails) {
            return [];
        }

        return auth()->user()->can('manage', $project)
            ? ['mention_invite' => ['project' => $project->key, 'emails' => $emails]]
            : ['warning' => collect($emails)->map(fn ($e) => "{$e} can't see this issue.")->implode(' ')];
    }

    /** The `peek` page prop: the issue named by ?issue=, or null when there is none the user can see. */
    protected function peek(Request $request): ?array
    {
        $issue = app(IssueService::class)->peek($request->user(), $request->query('issue'));

        return $issue ? $this->issueView($issue, $request->user()) : null;
    }
}
