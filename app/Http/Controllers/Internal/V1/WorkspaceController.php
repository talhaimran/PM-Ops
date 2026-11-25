<?php

namespace App\Http\Controllers\Internal\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\ListWorkspacesRequest;
use App\Http\Requests\Internal\OverrideRequest;
use App\Http\Requests\Internal\PlanRequest;
use App\Http\Requests\Internal\StaffRequest;
use App\Http\Requests\Internal\SupportSessionRequest;
use App\Http\Resources\Internal\WorkspaceResource;
use App\Models\User;
use App\Services\AdminApiService;
use App\Services\Entitlements;
use App\Services\Pagination;
use App\Services\SupportSessionService;
use App\Services\WorkspaceService;
use App\Services\WorkspaceUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Spec 9 / platform spec 4.1: the internal admin API. Thin: AdminApiService, Entitlements, WorkspaceService, SupportSessionService. */
class WorkspaceController extends Controller
{
    public function __construct(private AdminApiService $admin, private Entitlements $entitlements, private WorkspaceService $workspaces) {}

    public function index(ListWorkspacesRequest $request): AnonymousResourceCollection
    {
        return Pagination::collection(WorkspaceResource::class, $this->admin->list($request->validated()));
    }

    public function show(int $id): WorkspaceResource
    {
        return new WorkspaceResource($this->admin->find($id));
    }

    public function usage(int $id, WorkspaceUsage $usage): JsonResponse
    {
        return response()->json(['data' => $usage->usage($this->admin->find($id))]);
    }

    public function replaceEntitlements(PlanRequest $request, int $id): WorkspaceResource
    {
        $this->entitlements->replacePlan($this->admin->find($id), $request->validated('entitlements'), $request->validated('staff'));

        return $this->show($id);
    }

    public function setEntitlement(OverrideRequest $request, int $id, string $key): WorkspaceResource
    {
        $workspace = $this->admin->find($id);
        $this->entitlements->set($workspace, $this->admin->key($key), $request->validated('value'), 'override', $request->validated('staff'), $request->validated('reason'));

        return $this->show($id);
    }

    public function removeEntitlement(StaffRequest $request, int $id, string $key): WorkspaceResource
    {
        $workspace = $this->admin->find($id);
        $this->admin->removeOverride($workspace, $this->admin->key($key), $request->validated('staff'), $request->validated('reason'));

        return $this->show($id);
    }

    public function suspend(StaffRequest $request, int $id): WorkspaceResource
    {
        return $this->state($request, $id, 'workspace.suspended');
    }

    public function unsuspend(StaffRequest $request, int $id): WorkspaceResource
    {
        return $this->state($request, $id, 'workspace.unsuspended');
    }

    public function archive(StaffRequest $request, int $id): WorkspaceResource
    {
        return $this->state($request, $id, 'workspace.archived');
    }

    public function restore(StaffRequest $request, int $id): WorkspaceResource
    {
        return $this->state($request, $id, 'workspace.restored');
    }

    public function startSupportSession(SupportSessionRequest $request, int $id, SupportSessionService $support): JsonResponse
    {
        $workspace = $this->admin->find($id);
        $url = $support->start($workspace, User::find($request->validated('target_user_id')), $request->validated('staff'), $request->validated('reason'), $request->validated('minutes'));

        return response()->json(['data' => ['url' => $url]], 201);
    }

    private function state(StaffRequest $request, int $id, string $action): WorkspaceResource
    {
        $this->workspaces->changeState($this->admin->find($id), $action, staff: $request->validated('staff'), reason: $request->validated('reason'));

        return $this->show($id);
    }
}
