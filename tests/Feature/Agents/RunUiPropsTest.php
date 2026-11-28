<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Models\AgentRole;
use App\Models\AiProvider;
use App\Models\ProjectAgentSettings;
use App\Models\User;
use App\Services\AgentRunService;
use App\Services\AgentService;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// Step B (PR 4): the AI box's `run` and `ask` props on the issue page and the peek drawer.

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->web = makeProject($this->owner, 'WEB');
    $this->issue = makeIssue($this->web, $this->owner, ['title' => 'Login fails']);
    [$this->agent] = makeAgent($this->owner, AgentLevel::Write, name: 'Helper');
    $this->page = fn (User $u) => $this->actingAs($u)->get(w('/issues/WEB-1'));
});

test('ask lists only the viewer\'s own active agents and the roles, and only with Mode C and a provider', function () {
    // No provider: no Ask, nothing else AI.
    ($this->page)($this->owner)->assertInertia(fn (Assert $p) => $p->where('issue.ai.ask', null)->where('issue.ai.run', null));

    aiReady($this->ws);
    [$paused] = makeAgent($this->owner, AgentLevel::Write, name: 'Paused');
    app(AgentService::class)->pause($paused, $this->owner);
    makeAgent(User::factory()->create(), AgentLevel::Write, name: 'Theirs');

    ($this->page)($this->owner)->assertInertia(fn (Assert $p) => $p
        ->where('issue.ai.ask.agents', [['id' => $this->agent->id, 'label' => 'Helper']])
        ->where('issue.ai.ask.roles', fn ($roles) => collect($roles)->pluck('name')->contains('Triage') && array_keys($roles[0]) === ['id', 'name']));

    ProjectAgentSettings::create(['project_id' => $this->web->id, 'mode_c_enabled' => false]);
    ($this->page)($this->owner)->assertInertia(fn (Assert $p) => $p->where('issue.ai.ask', null));
});

test('a viewer gets no ask, and the peek drawer carries the same ai data', function () {
    aiReady($this->ws);
    $viewer = addMember($this->web, User::factory()->create(), ProjectRole::Viewer);
    makeAgent($viewer, AgentLevel::Write, name: 'ViewerBot');
    ($this->page)($viewer)->assertInertia(fn (Assert $p) => $p->where('issue.ai.ask', null));

    $this->actingAs($this->owner)->get(w('/projects/WEB/issues?issue=WEB-1'))
        ->assertInertia(fn (Assert $p) => $p->has('peek.issue.ai.ask.agents', 1));
});

test('run is the starter\'s latest run on the issue, only theirs, and survives losing the provider', function () {
    aiReady($this->ws);
    $role = AgentRole::whereNull('workspace_id')->where('name', 'Triage')->sole();
    $first = app(AgentRunService::class)->start($this->owner, $this->agent, $this->issue, $role, null);
    $second = app(AgentRunService::class)->start($this->owner, $this->agent, $this->issue, null, 'Tidy this up.');

    ($this->page)($this->owner)->assertInertia(fn (Assert $p) => $p
        ->where('issue.ai.run.id', $second->id)
        ->where('issue.ai.run.instruction', 'Tidy this up.')
        ->where('issue.ai.run.agent', 'Helper'));

    $other = addMember($this->web, User::factory()->create(), ProjectRole::Admin);
    ($this->page)($other)->assertInertia(fn (Assert $p) => $p->where('issue.ai.run', null));

    // Its proposals can still be applied without a provider; Ask is gone.
    AiProvider::query()->delete();
    ($this->page)($this->owner)->assertInertia(fn (Assert $p) => $p->where('issue.ai.run.id', $second->id)->where('issue.ai.ask', null));
});

test('no prop carries the key or endpoint', function () {
    aiReady($this->ws, ['provider' => 'openai_compatible', 'endpoint' => 'https://gateway.example.com/v1']);
    app(AgentRunService::class)->start($this->owner, $this->agent, $this->issue, null, 'Tidy this up.');

    $json = ($this->page)($this->owner)->assertOk()->getContent();

    expect($json)->not->toContain('sk-RUN-SECRET')->not->toContain('gateway.example.com');
});
