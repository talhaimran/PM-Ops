<?php

use App\Models\User;

// A-L4: every paginated list also takes `cursor` and returns `next_cursor`; `page` keeps working.

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->project = makeProject($this->owner, 'WEB');
    foreach (range(1, 60) as $n) {
        makeIssue($this->project, $this->owner, ['title' => "Issue {$n}", 'priority' => 'high']);
    }
    apiAs($this->owner);
});

/** Walks a REST list by cursor; returns the pages' keys. */
function walkRest(string $url, int $limit = 10): array
{
    $pages = [];
    $cursor = null;
    do {
        $response = test()->getJson($url.(str_contains($url, '?') ? '&' : '?').'per_page=25'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
        $pages[] = collect($response->json('data'))->pluck('key')->all();
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor && count($pages) < $limit);

    return $pages;
}

test('60 issues walk in 25, 25, 10 with no repeats and a null cursor at the end', function () {
    $pages = walkRest('/api/v1/projects/WEB/issues');

    expect(array_map('count', $pages))->toBe([25, 25, 10])
        ->and(collect($pages)->flatten()->unique()->count())->toBe(60);
    $this->getJson('/api/v1/projects/WEB/issues?per_page=25&page=3')->assertJsonPath('meta.next_cursor', null);
});

test('page=2 still works and matches the second cursor page', function () {
    $first = $this->getJson('/api/v1/projects/WEB/issues?per_page=25')->json();
    $viaCursor = $this->getJson('/api/v1/projects/WEB/issues?cursor='.$first['meta']['next_cursor'])->json('data.*.key');
    $viaPage = $this->getJson('/api/v1/projects/WEB/issues?per_page=25&page=2')->json('data.*.key');

    expect($viaCursor)->toBe($viaPage)->and($viaPage)->toHaveCount(25);
});

test('ties never repeat or skip: all 60 issues share one priority and still page cleanly in every direction', function (string $sort) {
    $pages = walkRest("/api/v1/projects/WEB/issues?sort={$sort}");

    expect(collect($pages)->flatten()->unique()->count())->toBe(60)->and(collect($pages)->flatten())->toHaveCount(60);
})->with(['priority', '-priority', 'type', 'status', 'assignee']);

test('a cursor carries its page size, so changing per_page mid-walk cannot misalign it', function () {
    $first = $this->getJson('/api/v1/projects/WEB/issues?per_page=10')->json();
    $next = $this->getJson('/api/v1/projects/WEB/issues?per_page=40&cursor='.$first['meta']['next_cursor'])->json();

    expect($next['data'])->toHaveCount(10)
        ->and(array_intersect(array_column($first['data'], 'key'), array_column($next['data'], 'key')))->toBe([]);
});

test('a malformed cursor is a 422 on cursor', function (string $cursor) {
    $this->getJson('/api/v1/projects/WEB/issues?cursor='.$cursor)->assertUnprocessable()->assertJsonValidationErrors('cursor');
})->with(['garbage', 'e30', 'WzAsMF0']);

test('the other lists take cursors too: projects, sprints, activity, search', function () {
    foreach (range(2, 4) as $n) {
        makeProject($this->owner, "P{$n}");
    }
    $first = $this->getJson('/api/v1/projects?per_page=2')->assertJsonCount(2, 'data')->json();
    $second = $this->getJson('/api/v1/projects?cursor='.$first['meta']['next_cursor'])->assertOk()->json();
    expect(array_merge(array_column($first['data'], 'key'), array_column($second['data'], 'key')))->toBe(['P2', 'P3', 'P4', 'WEB'])
        ->and($second['meta']['next_cursor'])->toBeNull();

    $this->getJson('/api/v1/issues?q=Issue&per_page=25')->assertJsonPath('meta.next_cursor', fn ($c) => is_string($c));
    $this->getJson('/api/v1/issues/WEB-1/activity?per_page=1')->assertOk()->assertJsonStructure(['meta' => ['next_cursor']]);
    $this->getJson('/api/v1/projects/WEB/sprints')->assertOk()->assertJsonStructure(['meta' => ['next_cursor']]);
});

test('MCP lists take cursor and return next_cursor in meta', function () {
    [, $token] = makeAgent($this->owner);

    $first = mcpJson($token, 'search_issues', ['project' => 'WEB', 'per_page' => 25]);
    $second = mcpJson($token, 'search_issues', ['project' => 'WEB', 'cursor' => $first['meta']['next_cursor']]);
    $third = mcpJson($token, 'search_issues', ['project' => 'WEB', 'cursor' => $second['meta']['next_cursor']]);

    expect([count($first['data']), count($second['data']), count($third['data'])])->toBe([25, 25, 10])
        ->and($third['meta']['next_cursor'])->toBeNull()
        ->and(collect([$first, $second, $third])->pluck('data')->flatten(1)->pluck('key')->unique()->count())->toBe(60);
});

test('a cursor is not an access token: a hand-made one over the endpoint max is rejected and one within it returns what page and per_page return', function () {
    [, $token] = makeAgent($this->owner);
    $cursor = fn (int $page, int $size) => rtrim(strtr(base64_encode("{$page}.{$size}"), '+/', '-_'), '=');

    asToken($token, ['Accept' => 'application/json, text/event-stream'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_issues', 'arguments' => ['project' => 'WEB', 'cursor' => $cursor(1, 1000)]]])
        ->assertOk()->assertJsonPath('result.isError', true);

    $viaCursor = mcpJson($token, 'search_issues', ['project' => 'WEB', 'cursor' => $cursor(3, 7)])['data'];
    $viaPage = mcpJson($token, 'search_issues', ['project' => 'WEB', 'page' => 3, 'per_page' => 7])['data'];
    expect($viaCursor)->toBe($viaPage)->and($viaPage)->toHaveCount(7);

    // Authorization still applies: another workspace member without access sees nothing through any cursor.
    $stranger = User::factory()->create();
    joinWorkspace($stranger);
    asWorkspaceMember($stranger);
    [, $strangerToken] = makeAgent($stranger);
    expect(mcpJson($strangerToken, 'search_issues', ['cursor' => $cursor(1, 50)])['data'])->toBe([]);
});
