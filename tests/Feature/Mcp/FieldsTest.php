<?php

use App\Enums\AgentLevel;
use App\Models\User;

// A3: `fields` trims a response to the keys the agent asks for. It never adds one the resource wouldn't return.

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->project = makeProject($this->owner, 'WEB');
    makeIssue($this->project, $this->owner, ['title' => 'First', 'description' => 'Long text', 'assignee' => $this->owner->email]);
    makeIssue($this->project, $this->owner, ['title' => 'Second']);
    [, $this->token] = makeAgent($this->owner, AgentLevel::Read);
});

test('REST: fields=key,title,status keeps only those keys on one issue and on lists', function () {
    asToken($this->token)->getJson('/api/v1/issues/WEB-1?fields=title,status')->assertOk()
        ->assertExactJson(['data' => ['key' => 'WEB-1', 'title' => 'First', 'status' => 'To Do']]);

    foreach (['/api/v1/projects/WEB/issues', '/api/v1/issues', '/api/v1/me/issues'] as $url) {
        $rows = asToken($this->token)->getJson($url.'?fields=key,title,status')->assertOk()->json('data');
        expect($rows)->not->toBeEmpty()->and(collect($rows)->map(fn ($r) => array_keys($r))->unique()->all())->toBe([['key', 'title', 'status']], $url);
    }
});

test('REST: unknown names are a 422 that lists the valid ones, and nothing is returned', function () {
    asToken($this->token)->getJson('/api/v1/issues/WEB-1?fields=title,nope')->assertUnprocessable()
        ->assertJsonValidationErrors('fields')
        ->assertJsonPath('errors.fields.0', fn ($m) => str_contains($m, 'nope') && str_contains($m, 'Valid fields: id, key, project, title'));
    asToken($this->token)->getJson('/api/v1/projects/WEB/issues?fields=nope')->assertUnprocessable()->assertJsonValidationErrors('fields');
});

test('fields cannot reach a key the resource does not return: nested-only keys are unknown unless loaded', function () {
    // `comments` exists on a full issue only; a list never loads it.
    asToken($this->token)->getJson('/api/v1/projects/WEB/issues?fields=comments')->assertUnprocessable();
    asToken($this->token)->getJson('/api/v1/issues/WEB-1?fields=comments')->assertOk()->assertJsonPath('data.comments', []);
    // Whatever the resource hides (internal columns) stays hidden.
    foreach (['rank', 'project_id', 'column_id', 'workspace_id', 'deleted_at'] as $internal) {
        asToken($this->token)->getJson("/api/v1/issues/WEB-1?fields={$internal}")->assertUnprocessable();
    }
});

test('fields names are trimmed, repeated names collapse, and key always comes back', function () {
    asToken($this->token)->getJson('/api/v1/issues/WEB-1?fields=%20title%20,title')->assertOk()
        ->assertExactJson(['data' => ['key' => 'WEB-1', 'title' => 'First']]);
});

test('MCP: get_issue, search_issues and my_issues honour fields', function () {
    expect(mcpJson($this->token, 'get_issue', ['issue' => 'WEB-1', 'fields' => 'key,title,status']))->toBe(['key' => 'WEB-1', 'title' => 'First', 'status' => 'To Do']);

    $search = mcpJson($this->token, 'search_issues', ['project' => 'WEB', 'fields' => 'title,priority']);
    expect(collect($search['data'])->map(fn ($r) => array_keys($r))->unique()->all())->toBe([['key', 'title', 'priority']]);

    $mine = mcpJson($this->token, 'my_issues', ['fields' => 'title']);
    expect($mine)->toBe([['key' => 'WEB-1', 'title' => 'First']]);
});

test('MCP: unknown fields error and list the card fields for lists', function () {
    $text = asToken($this->token, ['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_issues', 'arguments' => ['fields' => 'description']]])
        ->assertOk()->json('result');

    expect($text['isError'])->toBeTrue()->and(json_encode($text))->toContain('description', 'Valid fields: key, title, type, priority');
});

test('tools/list is static: no project, issue or user text in any tool description or schema', function () {
    $this->project->update(['name' => 'ZZZ-PROJECT-NAME', 'description' => 'ZZZ-PROJECT-DESC']);
    $this->owner->update(['name' => 'ZZZ-USER-NAME']);
    makeIssue($this->project, $this->owner, ['title' => 'ZZZ-ISSUE-TITLE']);

    $list = asToken($this->token, ['Accept' => 'application/json, text/event-stream'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()->json('result.tools');

    expect(json_encode($list))->not->toContain('ZZZ')->and(collect($list)->pluck('name'))->toContain('whoami', 'get_updates');
});
