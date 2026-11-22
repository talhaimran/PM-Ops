<?php

use App\Enums\ProjectRole;
use App\Models\AuditLog;
use App\Models\ProjectAgentSettings;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// A4: a per-project note for agents ("always fill Acceptance criteria"), returned by get_project, edited by project Admins in the web app.

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->ws = makeWorkspace($this->admin, 'acme');
    $this->project = makeProject($this->admin, 'WEB');
});

test('get_project returns agent_instructions over REST and MCP; null when none were written', function () {
    [, $token] = makeAgent($this->admin);
    asToken($token)->getJson('/api/v1/projects/WEB')->assertOk()->assertJsonPath('data.agent_instructions', null);

    ProjectAgentSettings::create(['project_id' => $this->project->id, 'instructions' => 'Always fill Acceptance criteria.']);

    asToken($token)->getJson('/api/v1/projects/WEB')->assertJsonPath('data.agent_instructions', 'Always fill Acceptance criteria.');
    expect(mcpJson($token, 'get_project', ['project' => 'WEB'])['agent_instructions'])->toBe('Always fill Acceptance criteria.');
    // Lists stay lean: one query for all projects, no instructions.
    asToken($token)->getJson('/api/v1/projects')->assertJsonMissingPath('data.0.agent_instructions');
});

test('a project Admin saves instructions on the settings page; the audit row says they changed, not what they say', function () {
    $text = 'Bugs need Severity. '.str_repeat('Long note. ', 100);

    $this->actingAs($this->admin)->patch(w('/projects/WEB'), ['agent_instructions' => $text])->assertSessionHasNoErrors();

    expect($this->project->fresh()->agentSettings->instructions)->toBe(trim($text));
    $row = AuditLog::where('action', 'project.agent_settings_changed')->sole();
    expect($row->metadata)->toEqual(['instructions_changed' => true])
        ->and($row->target_id)->toBe($this->project->id)
        ->and(json_encode($row->getAttributes()))->not->toContain('Severity');

    $this->get(w('/projects/WEB/settings'))->assertInertia(fn (Assert $page) => $page->where('project.agent_instructions', trim($text)));
});

test('saving the same text, or leaving the field out, logs nothing; clearing it stores null and logs', function () {
    $this->actingAs($this->admin)->patch(w('/projects/WEB'), ['agent_instructions' => 'Be brief.']);
    $this->patch(w('/projects/WEB'), ['agent_instructions' => 'Be brief.']);
    $this->patch(w('/projects/WEB'), ['name' => 'Renamed']);
    expect(AuditLog::where('action', 'project.agent_settings_changed')->count())->toBe(1);

    $this->patch(w('/projects/WEB'), ['agent_instructions' => '']);
    expect($this->project->fresh()->agentSettings->instructions)->toBeNull()
        ->and(AuditLog::where('action', 'project.agent_settings_changed')->count())->toBe(2);
});

test('instructions are at most 10,000 characters', function () {
    $this->actingAs($this->admin)->patch(w('/projects/WEB'), ['agent_instructions' => str_repeat('x', 10001)])->assertSessionHasErrors('agent_instructions');
    $this->patch(w('/projects/WEB'), ['agent_instructions' => str_repeat('x', 10000)])->assertSessionHasNoErrors();
});

test('only Admins who are people can edit: members, viewers and agents (any level) are refused', function () {
    $member = addMember($this->project, User::factory()->create(), ProjectRole::Member);
    $viewer = addMember($this->project, User::factory()->create(), ProjectRole::Viewer);
    foreach ([$member, $viewer] as $user) {
        $this->actingAs($user)->patch(w('/projects/WEB'), ['agent_instructions' => 'x'])->assertForbidden();
    }
    [, $token] = makeAgent($this->admin);
    asToken($token)->patchJson('/api/v1/projects/WEB', ['agent_instructions' => 'x'])->assertForbidden();

    expect(ProjectAgentSettings::count())->toBe(0)->and(AuditLog::where('action', 'project.agent_settings_changed')->count())->toBe(0);
});

test('a member can read the instructions on the settings page but is not offered an edit', function () {
    ProjectAgentSettings::create(['project_id' => $this->project->id, 'instructions' => 'Read me.']);
    $member = addMember($this->project, User::factory()->create(), ProjectRole::Member);

    $this->actingAs($member)->get(w('/projects/WEB/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('project.agent_instructions', 'Read me.')->where('can.manage', false));
});

test('deleting the project removes its settings', function () {
    ProjectAgentSettings::create(['project_id' => $this->project->id, 'instructions' => 'x']);

    $this->actingAs($this->admin)->delete(w('/projects/WEB'), ['confirm' => true]);

    expect(ProjectAgentSettings::count())->toBe(0);
});
