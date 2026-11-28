<?php

namespace App\Http\Controllers\Web\Concerns;

use App\Enums\AgentStatus;
use App\Http\Resources\AgentRunResource;
use App\Http\Resources\IssueResource;
use App\Models\Agent;
use App\Models\AgentRole;
use App\Models\AgentRun;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\AiSettingsService;
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
        // One lookup for the description and every comment (3-R12).
        $html = $text->htmlMany([$issue->description, ...$issue->comments->values()->pluck('body')->all()], $user, $issue->project);
        $data['description_html'] = array_shift($html);
        $data['comments'] = $issue->comments->values()->map(fn ($comment, $i) => [
            ...$data['comments'][$i],
            'body_html' => $html[$i],
            'can_edit' => $comment->isWrittenBy($user),
        ])->all();
        $data['pending_approvals'] = $issue->pendingApprovals->map(fn ($approval, $i) => [
            ...$data['pending_approvals'][$i],
            'can_decide' => $user->can('decide', $approval),
        ])->all();
        $data['ai'] = $this->aiBox($issue, $user);

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
     * Spec 5.1: the viewer's latest run on this issue (B-D6) and what "Ask agent" offers. No `ask` without a provider, Mode C on
     * and the right to write; a run stays even without a provider because its proposals can still be applied. Never the key.
     *
     * @return array{run: ?array<string, mixed>, ask: ?array<string, mixed>}
     */
    private function aiBox(Issue $issue, User $user): array
    {
        $run = AgentRun::visibleTo($user)->where('issue_id', $issue->id)->with(['agent', 'changes'])->latest('id')->first();
        $workspaceId = $issue->project->workspace_id;
        $ask = null;
        if (app(AiSettingsService::class)->modeCEnabled($issue->project) && $user->can('contribute', $issue->project)) {
            $lastRun = AgentRun::where('user_id', $user->id)->selectRaw('agent_id, max(id) as last_run')->groupBy('agent_id');
            $ask = [
                'agents' => Agent::where('agents.owner_user_id', $user->id)->where('agents.workspace_id', $workspaceId)->where('status', AgentStatus::Active)
                    ->leftJoinSub($lastRun, 'lr', 'lr.agent_id', '=', 'agents.id')->orderByRaw('lr.last_run is null')->orderByDesc('lr.last_run')->orderBy('agents.name')
                    ->get(['agents.id', 'agents.name'])->map(fn (Agent $a) => ['id' => $a->id, 'label' => $a->label()])->all(),
                'roles' => AgentRole::where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId))->orderBy('name')->get(['id', 'name'])
                    ->map(fn (AgentRole $r) => ['id' => $r->id, 'name' => $r->name])->all(),
            ];
        }

        return ['run' => $run ? (new AgentRunResource($run))->resolve() : null, 'ask' => $ask];
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
