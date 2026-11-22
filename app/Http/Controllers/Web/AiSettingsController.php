<?php

namespace App\Http\Controllers\Web;

use App\Enums\AiProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateAiSettingsRequest;
use App\Services\AiSettingsService;
use App\Services\CurrentWorkspace;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** /{workspace}/settings/ai (spec 8, A5). Web only for Owners and Admins: tokens and agents never reach it, and no prop carries the key. */
class AiSettingsController extends Controller
{
    public function __construct(private CurrentWorkspace $current, private AiSettingsService $ai, private WorkspaceService $workspaces) {}

    public function show(): Response
    {
        $workspace = $this->current->get();
        Gate::authorize('manageAi', $workspace);
        $connection = $this->ai->for($workspace);
        // A server-set endpoint may carry a login or token: show only where it points.
        $endpoint = $connection['endpoint'] ?? null;
        $shown = $connection && $connection['source'] === 'env' && $endpoint !== null ? $this->ai->displayEndpoint($endpoint) : $endpoint;

        return Inertia::render('Workspaces/Settings/Ai', [
            'connection' => $connection ? [
                'source' => $connection['source'],
                'provider' => $connection['provider']->value,
                'model' => $connection['model'],
                'endpoint' => $shown,
                'endpoint_hidden' => $endpoint !== null && $shown === null,
                'has_key' => $this->ai->hasKey($workspace),
                ...$this->ai->usage($workspace),
            ] : null,
            'providers' => array_map(fn (AiProviderType $p) => ['value' => $p->value, 'label' => $p->label()], AiProviderType::cases()),
            'env_ignored' => $this->ai->envIgnored(),
            'mode_c' => (bool) $workspace->setting('mode_c_enabled'),
        ]);
    }

    public function update(UpdateAiSettingsRequest $request): RedirectResponse
    {
        $workspace = $this->current->get();
        Gate::authorize('manageAi', $workspace);
        $this->ai->update($workspace, $request->user(), $request->validated());

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $workspace = $this->current->get();
        Gate::authorize('manageAi', $workspace);
        $this->ai->remove($workspace, $request->user());

        return back();
    }

    public function cap(Request $request): RedirectResponse
    {
        $workspace = $this->current->get();
        Gate::authorize('manageAi', $workspace);
        $cap = $request->validate(['monthly_token_cap' => ['nullable', 'integer', 'min:1', 'max:1000000000000']])['monthly_token_cap'];
        $this->ai->setCap($workspace, $request->user(), $cap);

        return back();
    }

    /** The workspace-wide Mode C switch; it is also an agent policy change, so it logs agent_policy.changed. */
    public function modeC(Request $request): RedirectResponse
    {
        $workspace = $this->current->get();
        Gate::authorize('manageAi', $workspace);
        $on = $request->validate(['mode_c_enabled' => ['required', 'boolean']])['mode_c_enabled'];
        $this->workspaces->updateSettings($workspace, ['mode_c_enabled' => (bool) $on], 'agent_policy.changed');

        return back();
    }
}
