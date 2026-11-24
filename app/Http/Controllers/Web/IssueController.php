<?php

namespace App\Http\Controllers\Web;

use App\Enums\DateShift;
use App\Enums\LinkType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\PresentsIssue;
use App\Http\Requests\Issues\CloneIssueRequest;
use App\Http\Requests\Issues\CommentRequest;
use App\Http\Requests\Issues\DestroyIssueRequest;
use App\Http\Requests\Issues\LinkRequest;
use App\Http\Requests\Issues\StoreIssueRequest;
use App\Http\Requests\Issues\UpdateCommentRequest;
use App\Http\Requests\Issues\UpdateIssueRequest;
use App\Http\Requests\Issues\WatcherRequest;
use App\Http\Resources\ActivityResource;
use App\Models\Issue;
use App\Models\Project;
use App\Services\ActivityLogger;
use App\Services\CommentService;
use App\Services\IssueService;
use App\Services\LinkService;
use App\Services\ProjectService;
use App\Services\UndoService;
use App\Services\WatcherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IssueController extends Controller
{
    use PresentsIssue;

    public function __construct(
        private ProjectService $projects,
        private IssueService $issues,
        private CommentService $comments,
        private UndoService $undo,
        private LinkService $links,
        private WatcherService $watchers,
    ) {}

    public function store(StoreIssueRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('contribute', $project);
        $issue = $this->issues->create($project, $request->user(), $request->validated());

        return redirect()->route('issues.show', $issue->key);
    }

    public function show(Request $request, string $key): Response
    {
        $issue = $this->issues->resolve($request->user(), $key);

        return Inertia::render('Issues/Show', [
            ...$this->issueView($issue, $request->user()),
            'project' => ['key' => $issue->project->key, 'name' => $issue->project->name],
            'activity' => ActivityResource::collection(tap($issue->activities()->visibleTo($request->user())->with(['user', 'agent.owner'])->latest('id')->limit(50)->get(), fn ($rows) => ActivityLogger::hideUnseenOriginals($rows, $request->user())))->resolve(),
        ]);
    }

    public function update(UpdateIssueRequest $request, string $key): RedirectResponse
    {
        $issue = $this->issues->resolve($request->user(), $key);
        Gate::authorize('update', $issue);
        $data = $request->validated();
        // overwrite (web only, spec 7.11): the conflict dialog's "Save mine over theirs", sent with the current version.
        $flags = $request->validate(['undo_of' => ['nullable', 'integer', 'min:1'], 'overwrite' => ['sometimes', 'boolean'], 'remember_date_shift' => ['sometimes', 'boolean']]);

        if ($flags['undo_of'] ?? null) {
            // The undo sends the version right after the change; if the issue changed since, IssueService refuses it.
            $this->issues->update($issue, $request->user(), $data, 'undid');

            return back();
        }

        $before = $this->undo->forUpdate($issue, array_keys($data), $request->user());
        $marker = $this->undo->token($issue);
        $issue = $this->issues->update($issue, $request->user(), $data, ($flags['overwrite'] ?? false) ? 'overwrote' : 'updated');
        if (($flags['remember_date_shift'] ?? false) && isset($data['shift_successors'])) {
            // The shift dialog's "Remember my choice" (2-R9), saved once the update went through.
            $request->user()->update(['date_shift' => ($data['shift_successors'] ? DateShift::Always : DateShift::Never)->value]);
        }
        $token = $this->undo->token($issue);
        // 2-R6: "ask" moved nothing; the dialog offers Shift dates or Keep dates.
        $shifts = $issue->proposedShifts ? ['dateShift' => ['key' => $issue->key, 'shifts' => $issue->proposedShifts]] : [];
        // Past the shift limit nothing is proposed ([]); say why, as REST and MCP do.
        if ($issue->proposedShifts === []) {
            $shifts = ['warning' => LinkService::shiftWarning($issue, [])];
        }

        if ($token !== $marker) {
            $status = $before['status'] ?? null;

            return back()->with($shifts)->with('undo', [
                'message' => $status !== null && $status !== $issue->status->name ? "{$issue->key} moved to {$issue->status->name}" : "{$issue->key} updated",
                'method' => 'patch',
                'url' => route('issues.update', $issue->key),
                'data' => [...$before, 'version' => $token, 'undo_of' => $token],
            ]);
        }

        return back()->with($shifts);
    }

    public function destroy(DestroyIssueRequest $request, string $key): RedirectResponse
    {
        $issue = $this->issues->resolve($request->user(), $key);
        Gate::authorize('delete', $issue);
        $this->issues->delete($issue, $request->user());

        return redirect()->route('projects.show', $issue->project->key)->with('undo', [
            'message' => "{$issue->key} deleted",
            'method' => 'post',
            'url' => route('issues.restore', $issue->key),
            'data' => [],
        ]);
    }

    public function restore(Request $request, string $key): RedirectResponse
    {
        $issue = $this->issues->resolveDeleted($request->user(), $key);
        Gate::authorize('delete', $issue);
        $this->issues->restore($issue, $request->user());

        return back();
    }

    /** Spec 7.5; Undo (2-R10) sends the same type and key back to unlink(), with undo_of so it offers no Undo of its own. */
    public function link(LinkRequest $request, string $key): RedirectResponse
    {
        $from = $this->issues->resolve($request->user(), $key);
        Gate::authorize('update', $from);
        $link = $this->links->link($from, $request->validated('type'), $this->issues->resolve($request->user(), $request->validated('to')), $request->user());
        // A replaced original goes back on Undo; its key only if this person can see it ("?" finds nothing, so Undo says
        // it couldn't be restored without naming it).
        $replaced = $link->replaced ? (Issue::visibleKeys($request->user(), [$link->replaced->key])->isNotEmpty() ? $link->replaced->key : '?') : null;

        return $request->filled('undo_of') ? back() : back()->with('undo', [
            'message' => "{$link->source->key} {$link->type->value} {$link->target->key}",
            'method' => 'delete',
            'url' => route('issues.links.destroy', $from->key),
            'data' => [...$request->validated(), 'undo_of' => $from->version, ...($replaced ? ['restore' => $replaced] : [])],
        ]);
    }

    /** Spec 7.9; Undo deletes the clone (and its sub-tasks), as any delete. */
    public function clone(CloneIssueRequest $request, string $key): RedirectResponse
    {
        $issue = $this->issues->resolve($request->user(), $key);
        Gate::authorize('update', $issue);
        $clone = $this->issues->clone($issue, $request->user(), $request->boolean('subtasks'), $request->boolean('links'));

        return redirect()->route('issues.show', $clone->key)->with('undo', [
            'message' => "{$clone->key} cloned from {$issue->key}",
            'method' => 'delete',
            'url' => route('issues.destroy', $clone->key),
            'data' => ['confirm' => true],
        ]);
    }

    public function unlink(LinkRequest $request, string $key): RedirectResponse
    {
        $issue = $this->issues->resolve($request->user(), $key);
        $other = $this->issues->resolve($request->user(), $request->validated('to'));
        Gate::authorize('unlink', [$issue, $other]);
        $restore = $request->validate(['restore' => ['nullable', 'string', 'max:30']])['restore'] ?? null;
        $removed = $this->links->unlink($issue, $request->validated('type'), $other, $request->user());

        if ($restore !== null && $removed->type === LinkType::Duplicates && ! $this->links->restoreOriginal($removed->source, $this->issues->peek($request->user(), $restore), $request->user())) {
            return back()->withErrors(['undo' => "Undone. The earlier original couldn't be restored."]);
        }

        return $request->filled('undo_of') ? back() : back()->with('undo', [
            'message' => 'Link removed',
            'method' => 'post',
            'url' => route('issues.links.store', $issue->key),
            'data' => [...$request->validated(), 'undo_of' => $issue->version],
        ]);
    }

    public function watch(WatcherRequest $request, string $key): RedirectResponse
    {
        $issue = $this->issues->resolve($request->user(), $key);
        $user = $this->watchers->target($issue, $request->validated('user'), $request->user());
        Gate::authorize('watch', [$issue, $user]);
        $this->watchers->watch($issue, $user, $request->user());

        return back();
    }

    public function unwatch(WatcherRequest $request, string $key): RedirectResponse
    {
        $issue = $this->issues->resolve($request->user(), $key);
        $user = $this->watchers->target($issue, $request->validated('user'), $request->user());
        Gate::authorize('unwatch', [$issue, $user]);
        $this->watchers->unwatch($issue, $user, $request->user());

        return back();
    }

    public function restoreComment(Request $request, int $comment): RedirectResponse
    {
        $comment = $this->comments->resolve($request->user(), $comment, deleted: true);
        Gate::authorize('delete', $comment);
        $this->comments->restore($comment);

        return back();
    }

    public function comment(CommentRequest $request, string $key): RedirectResponse
    {
        $issue = $this->issues->resolve($request->user(), $key);
        Gate::authorize('comment', $issue);
        $this->comments->add($issue, $request->user(), $request->validated('body'));

        return back();
    }

    public function updateComment(UpdateCommentRequest $request, int $comment): RedirectResponse
    {
        $comment = $this->comments->resolve($request->user(), $comment);
        Gate::authorize('update', $comment);
        $this->comments->update($comment, $request->validated('body'), (int) $request->validated('version'));

        return back();
    }

    public function destroyComment(Request $request, int $comment): RedirectResponse
    {
        $comment = $this->comments->resolve($request->user(), $comment);
        Gate::authorize('delete', $comment);
        $this->comments->delete($comment);

        return back()->with('undo', [
            'message' => 'Comment deleted',
            'method' => 'post',
            'url' => route('comments.restore', $comment->id),
            'data' => [],
        ]);
    }
}
