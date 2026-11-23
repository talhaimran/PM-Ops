<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\PresentsIssue;
use App\Http\Requests\Issues\CommentRequest;
use App\Http\Requests\Issues\DestroyIssueRequest;
use App\Http\Requests\Issues\StoreIssueRequest;
use App\Http\Requests\Issues\UpdateCommentRequest;
use App\Http\Requests\Issues\UpdateIssueRequest;
use App\Http\Resources\ActivityResource;
use App\Models\Project;
use App\Services\CommentService;
use App\Services\IssueService;
use App\Services\ProjectService;
use App\Services\UndoService;
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
            'activity' => ActivityResource::collection($issue->activities()->with(['user', 'agent.owner'])->latest('id')->limit(50)->get())->resolve(),
        ]);
    }

    public function update(UpdateIssueRequest $request, string $key): RedirectResponse
    {
        $issue = $this->issues->resolve($request->user(), $key);
        Gate::authorize('update', $issue);
        $data = $request->validated();
        // overwrite (web only, spec 7.11): the conflict dialog's "Save mine over theirs", sent with the current version.
        $flags = $request->validate(['undo_of' => ['nullable', 'integer'], 'overwrite' => ['sometimes', 'boolean']]);

        if ($flags['undo_of'] ?? null) {
            // The undo sends the version right after the change; if the issue changed since, IssueService refuses it.
            $this->issues->update($issue, $request->user(), $data, 'undid');

            return back();
        }

        $before = $this->undo->forUpdate($issue, array_keys($data));
        $marker = $this->undo->token($issue);
        $issue = $this->issues->update($issue, $request->user(), $data, ($flags['overwrite'] ?? false) ? 'overwrote' : 'updated');
        $token = $this->undo->token($issue);

        if ($token !== $marker) {
            $status = $before['status'] ?? null;

            return back()->with('undo', [
                'message' => $status !== null && $status !== $issue->status->name ? "{$issue->key} moved to {$issue->status->name}" : "{$issue->key} updated",
                'method' => 'patch',
                'url' => route('issues.update', $issue->key),
                'data' => [...$before, 'version' => $token, 'undo_of' => $token],
            ]);
        }

        return back();
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
