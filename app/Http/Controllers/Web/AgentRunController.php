<?php

namespace App\Http\Controllers\Web;

use App\Enums\AgentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentRuns\StartRunRequest;
use App\Models\Agent;
use App\Models\AgentRole;
use App\Services\AgentRunService;
use App\Services\ChangeSetService;
use App\Services\CurrentWorkspace;
use App\Services\IssueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Step B: a person starts and stops in-app agent runs and applies or discards what they proposed (B-D6: their own runs only). */
class AgentRunController extends Controller
{
    public function __construct(private AgentRunService $runs, private ChangeSetService $changes, private IssueService $issues, private CurrentWorkspace $current) {}

    public function start(StartRunRequest $request, string $key): RedirectResponse
    {
        $user = $request->user();
        $issue = $this->issues->resolve($user, $key);
        $workspaceId = $this->current->id();
        $agents = Agent::where('owner_user_id', $user->id)->where('workspace_id', $workspaceId);
        $agent = (clone $agents)->find($request->validated('agent'))
            ?? throw ValidationException::withMessages(['agent' => 'Choose one of your own agents: '.((clone $agents)->where('status', AgentStatus::Active)->orderBy('name')->pluck('name')->implode(', ') ?: 'none yet').'.']);
        $role = null;
        if ($request->filled('role')) {
            $roles = AgentRole::where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId));
            $role = (clone $roles)->find($request->validated('role'))
                ?? throw ValidationException::withMessages(['role' => 'That role was not found. Roles: '.$roles->orderBy('name')->pluck('name')->implode(', ').'.']);
        }
        $this->runs->start($user, $agent, $issue, $role, $request->validated('instruction'));

        return back();
    }

    public function cancel(Request $request, int $run): RedirectResponse
    {
        [$run] = $this->changes->resolve($request->user(), $run);
        $this->runs->cancel($run, $request->user());

        return back();
    }

    /** All proposed changes, or (with $change) one. */
    public function apply(Request $request, int $run, ?int $change = null): RedirectResponse
    {
        [$run, $change] = $this->changes->resolve($request->user(), $run, $change);
        $result = $this->changes->apply($run, $request->user(), $change);
        $count = count($result['conflicts']);

        return $count ? back()->with('warning', ($count === 1 ? '1 change' : "{$count} changes")." couldn't be applied: ".$result['conflicts'][0]['message']) : back();
    }

    /** Everything not applied yet, or (with $change) one. */
    public function discard(Request $request, int $run, ?int $change = null): RedirectResponse
    {
        [$run, $change] = $this->changes->resolve($request->user(), $run, $change);
        $this->changes->discard($run, $request->user(), $change);

        return back();
    }
}
