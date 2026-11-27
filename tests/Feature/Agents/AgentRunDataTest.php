<?php

use App\Enums\AgentRunStatus;
use App\Models\AgentRole;
use App\Models\AgentRun;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiSettingsService;
use Illuminate\Support\Facades\Schema;

// Step B: the run ledger (B-R16/B-R18), the built-in roles (B-R5) and month usage from runs (B-R9).

function runOf(Workspace $ws, User $user, int $in, int $out): AgentRun
{
    return AgentRun::create(['workspace_id' => $ws->id, 'issue_key' => 'WEB-1', 'user_id' => $user->id, 'role_name' => 'Triage',
        'status' => AgentRunStatus::Finished, 'tokens_in' => $in, 'tokens_out' => $out]);
}

test('counts month usage from runs', function () {
    $user = User::factory()->create();
    $ws = makeWorkspace($user, 'acme');
    $other = makeWorkspace($user, 'other');
    AiProvider::create(['workspace_id' => $ws->id, 'monthly_token_cap' => 5000, 'tokens_used_month' => 999]);

    $this->travelTo(now()->startOfMonth()->subSecond());
    runOf($ws, $user, 700, 300);
    $this->travelBack();
    runOf($ws, $user, 100, 20);
    runOf($ws, $user, 50, 5);
    runOf($other, $user, 4000, 4000);

    expect(AgentRun::monthTokens($ws))->toBe(175)
        ->and(app(AiSettingsService::class)->usage($ws))->toBe(['cap' => 5000, 'used' => 175])
        ->and(app(AiSettingsService::class)->usage($other))->toBe(['cap' => null, 'used' => 8000]);
});

test('the four built-in roles are seeded with their B-R5 tool sets', function () {
    $roles = AgentRole::whereNull('workspace_id')->orderBy('id')->get();

    expect($roles->mapWithKeys(fn ($r) => [$r->name => $r->allowed_tools])->all())->toEqual([
        'Triage' => ['read_issue', 'search_issues', 'propose_fields', 'propose_link'],
        'Writer' => ['read_issue', 'propose_fields'],
        'Planner' => ['read_issue', 'search_issues', 'propose_child_issue', 'propose_link'],
        'Summariser' => ['read_issue', 'search_issues'],
    ])
        ->and($roles->pluck('apply_mode')->unique()->all())->toBe(['propose'])
        ->and($roles->every(fn ($r) => filled($r->instructions)))->toBeTrue();
});

test('activities, notifications and audit logs can point at a run', function () {
    foreach (['activities', 'notifications', 'audit_logs'] as $table) {
        expect(Schema::hasColumn($table, 'agent_run_id'))->toBeTrue();
    }
});

test('a workspace defaults to 15 steps, 50000 tokens and 180 seconds per run', function () {
    $ws = makeWorkspace(User::factory()->create(), 'acme');

    expect([$ws->setting('run_max_steps'), $ws->setting('run_token_budget'), $ws->setting('run_timeout_seconds')])->toBe([15, 50000, 180]);
});
