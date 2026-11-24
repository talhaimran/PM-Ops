<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Issues\BulkStoreIssueRequest;
use App\Http\Requests\Issues\BulkUpdateIssueRequest;
use App\Http\Requests\Issues\DestroyIssueRequest;
use App\Http\Requests\Issues\LinkRequest;
use App\Http\Requests\Issues\StoreIssueRequest;
use App\Http\Requests\Issues\UpdateIssueRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\ApprovalRequestResource;
use App\Http\Resources\IssueResource;
use App\Services\IssueService;
use App\Services\LinkService;
use App\Services\Pagination;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class IssueController extends Controller
{
    public function __construct(private ProjectService $projects, private IssueService $issues, private LinkService $links) {}

    /**
     * Create an issue in a project.
     *
     * Use names, not IDs: `status` is a column name, `assignee` a member email, `labels` label names,
     * `epic` and `parent` issue keys (e.g. WEB-3). Unknown values return 422 listing valid options.
     */
    public function store(StoreIssueRequest $request, string $key): IssueResource
    {
        $project = $this->projects->resolve($request->user(), $key);
        Gate::authorize('contribute', $project);

        return new IssueResource($this->issues->create($project, $request->user(), $request->validated()));
    }

    /**
     * Create up to 50 issues in one project, all or nothing.
     *
     * Body: `{"issues": [{...same fields as create}, ...]}`. Errors are keyed `issues.{index}.{field}`.
     */
    public function bulkStore(BulkStoreIssueRequest $request, string $key): JsonResponse
    {
        $project = $this->projects->resolve($request->user(), $key);
        Gate::authorize('contribute', $project);

        return IssueResource::collection($this->issues->createMany($project, $request->user(), $request->validated('issues')))
            ->response()->setStatusCode(201);
    }

    /**
     * Update up to 50 issues across your projects, all or nothing.
     *
     * Body: `{"updates": [{"key": "WEB-1", "version": 3, ...fields to change}, ...]}`. Errors are keyed `updates.{index}.{field}`.
     * Each item sends the `version` it read; if any changed since, nothing is saved: 409 with `conflicts` naming each one.
     * An agent's moves to a Done-category status wait for a person: 202 with `status: pending_approval` and `approval_requests`.
     */
    public function bulkUpdate(BulkUpdateIssueRequest $request): AnonymousResourceCollection|JsonResponse
    {
        $updated = $this->issues->updateMany($request->user(), $request->validated('updates'));
        $held = $updated->pluck('heldApproval')->filter()->values();
        $response = IssueResource::collection($updated);

        return $held->isEmpty() ? $response : $response
            ->additional(['status' => 'pending_approval', 'approval_requests' => ApprovalRequestResource::collection($held)->resolve()])
            ->response()->setStatusCode(202);
    }

    /** Get an issue by key (e.g. WEB-12) with sub-tasks, epic issues and comments. `fields` (comma-separated keys) trims the answer; `key` always stays. */
    public function show(Request $request, string $key): IssueResource
    {
        $fields = $request->validate(['fields' => ['nullable', 'string', 'max:500']])['fields'] ?? null;

        return (new IssueResource($this->issues->loadFull($this->issues->resolve($request->user(), $key), $request->user())))->fields($fields);
    }

    /**
     * Update an issue. Send only the fields to change; send null to clear assignee, epic, parent, points or due date.
     *
     * Send the `version` you read (required). If the issue changed since, nothing is saved: 409 `{"error": "conflict",
     * "current_version": …, "changes": [{"field", "from", "to", "by", "agent", "via", "at"}], "issue": {…}}`. Read it
     * again and resend. Moves (`/move`, `/rank`) need no version.
     *
     * An agent moving the issue to a Done-category status gets 202 `{"status": "pending_approval", "approval_request": {...},
     * "issue": {...}}`: a person must approve the move; the other fields are applied. `reason` (optional) says why.
     */
    public function update(UpdateIssueRequest $request, string $key): IssueResource|JsonResponse
    {
        $issue = $this->issues->resolve($request->user(), $key);
        Gate::authorize('update', $issue);
        $issue = $this->issues->update($issue, $request->user(), $request->validated());

        return $issue->heldApproval ? ApprovalRequestResource::pending($issue->heldApproval, new IssueResource($issue)) : new IssueResource($issue);
    }

    /** Delete an issue and its sub-tasks (requires confirm: true). */
    public function destroy(DestroyIssueRequest $request, string $key): Response
    {
        $issue = $this->issues->resolve($request->user(), $key);
        Gate::authorize('delete', $issue);
        $this->issues->delete($issue, $request->user());

        return response()->noContent();
    }

    /**
     * Link this issue to another (spec 7.5): `{"type": "blocks", "to": "WEB-15"}`.
     *
     * Types: blocks, precedes, relates to, duplicates, clones; the reverse names (is blocked by, follows, is duplicated
     * by, is cloned by) are accepted and stored the other way round. Links may cross projects in your workspace. blocks
     * and precedes can't loop (422 naming the loop). Needs write access to this issue's project and sight of the other
     * issue (404 otherwise). Answers 201 with the link as stored, e.g. `{"from": "WEB-12", "type": "blocks", "to": "WEB-15"}`.
     */
    public function link(LinkRequest $request, string $key): JsonResponse
    {
        $from = $this->issues->resolve($request->user(), $key);
        Gate::authorize('update', $from);
        $link = $this->links->link($from, $request->validated('type'), $this->issues->resolve($request->user(), $request->validated('to')), $request->user());

        return response()->json(['data' => ['from' => $link->source->key, 'type' => $link->type->value, 'to' => $link->target->key]], 201);
    }

    /** Remove a link: the same body as adding it (`type` may be the reverse name). Needs write access to either issue's project. */
    public function unlink(LinkRequest $request, string $key): Response
    {
        $issue = $this->issues->resolve($request->user(), $key);
        $other = $this->issues->resolve($request->user(), $request->validated('to'));
        Gate::authorize('unlink', [$issue, $other]);
        $this->links->unlink($issue, $request->validated('type'), $other, $request->user());

        return response()->noContent();
    }

    /** Activity log for an issue, newest first. `via` is web, api or mcp; `token_name` names the API token used. */
    public function activity(Request $request, string $key): AnonymousResourceCollection
    {
        $issue = $this->issues->resolve($request->user(), $key);

        [$perPage, $page] = Pagination::resolve($request->validate(['cursor' => ['nullable', 'string', 'max:100']])['cursor'] ?? null, $request->integer('per_page'), $request->integer('page'), 50);

        return Pagination::collection(ActivityResource::class, $issue->activities()->visibleTo($request->user())->with(['user', 'agent.owner'])->latest('id')->paginate($perPage, page: $page));
    }
}
