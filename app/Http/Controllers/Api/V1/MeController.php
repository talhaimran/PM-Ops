<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AgentService;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /** Who you are: the agent (null for a person), its owner, workspace, projects, and the rate limit left. Same as MCP `whoami`. */
    public function show(Request $request, AgentService $agents): JsonResponse
    {
        return response()->json(['data' => $agents->whoami($request->user())]);
    }

    /**
     * How this agent's approval requests ended (approved, rejected, expired, stale), oldest first. Pass the last
     * `meta.next_cursor` back as `cursor` to get what happened since; an empty list keeps the same cursor.
     */
    public function updates(Request $request, ApprovalService $approvals): JsonResponse
    {
        $data = $request->validate(ApprovalService::UPDATE_RULES);
        $result = $approvals->updatesFor($request->user(), $request->user()->agent(), $data['cursor'] ?? null, $data['per_page'] ?? 25);

        return response()->json(['data' => $result['updates'], 'meta' => ['next_cursor' => $result['next_cursor']]]);
    }
}
