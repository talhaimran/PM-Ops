<?php

use App\Enums\LinkType;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\GetIssue;
use App\Mcp\Tools\LinkIssues;
use App\Mcp\Tools\SearchIssues;
use App\Mcp\Tools\UnlinkIssues;
use App\Models\Activity;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;

// Spec 7.5: issue links, stored once in the forward direction; web, REST and MCP share LinkService.

beforeEach(function () {
    $this->admin = User::factory()->create(['email' => 'admin@example.com']);
    $this->web = makeProject($this->admin, 'WEB');
    foreach (range(1, 5) as $n) {
        makeIssue($this->web, $this->admin, ['title' => "Issue {$n}"]);
    }
    apiAsPerson($this->admin);
});

function web(int $n): Issue
{
    return Issue::whereHas('project', fn ($p) => $p->where('key', 'WEB'))->where('number', $n)->sole();
}

function linkRow(Issue $source, Issue $target): ?IssueLink
{
    return IssueLink::where('source_issue_id', $source->id)->where('target_issue_id', $target->id)->first();
}

test('each type and its reverse name store one forward row', function (string $forward, string $reverse) {
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => $forward, 'to' => 'WEB-2'])->assertCreated()
        ->assertJsonPath('data', ['from' => 'WEB-1', 'type' => $forward, 'to' => 'WEB-2']);
    // The reverse name, sent from the other side, is the same link.
    $this->postJson('/api/v1/issues/WEB-4/links', ['type' => $reverse, 'to' => 'WEB-3'])->assertCreated()
        ->assertJsonPath('data', ['from' => 'WEB-3', 'type' => $forward, 'to' => 'WEB-4']);

    expect(IssueLink::count())->toBe(2)
        ->and(linkRow(web(1), web(2))->type)->toBe(LinkType::from($forward))
        ->and(linkRow(web(3), web(4))->type)->toBe(LinkType::from($forward));
})->with([
    ['blocks', 'is blocked by'],
    ['precedes', 'follows'],
    ['duplicates', 'is duplicated by'],
    ['clones', 'is cloned by'],
]);

test('relates to is one row either way, stored with the lower id first; names are case-insensitive and take underscores', function () {
    $this->postJson('/api/v1/issues/WEB-2/links', ['type' => 'Relates_To', 'to' => 'WEB-1'])->assertCreated()
        ->assertJsonPath('data', ['from' => 'WEB-1', 'type' => 'relates to', 'to' => 'WEB-2']);
    expect(linkRow(web(1), web(2)))->not->toBeNull();
    $this->postJson('/api/v1/issues/WEB-2/links', ['type' => 'relates to', 'to' => 'WEB-1'])->assertUnprocessable()
        ->assertJsonPath('errors.type.0', 'WEB-1 already relates to WEB-2.');

    expect(IssueLink::count())->toBe(1);
});

test('an unknown type lists the valid names', function () {
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'depends on', 'to' => 'WEB-2'])->assertUnprocessable()
        ->assertJsonPath('errors.type.0', 'Unknown link type "depends on". Use one of: blocks, is blocked by, precedes, follows, relates to, duplicates, is duplicated by, clones, is cloned by.');
});

test('a duplicate link and a self-link are refused', function () {
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'WEB-2'])->assertCreated();
    $this->postJson('/api/v1/issues/WEB-2/links', ['type' => 'is blocked by', 'to' => 'WEB-1'])->assertUnprocessable()
        ->assertJsonPath('errors.type.0', 'WEB-1 already blocks WEB-2.');
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'web-1'])->assertUnprocessable()
        ->assertJsonPath('errors.to.0', "An issue can't be linked to itself.");

    expect(IssueLink::count())->toBe(1);
});

test('blocks and precedes may not loop, and the error names the loop; relates to may', function (string $type) {
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => $type, 'to' => 'WEB-2'])->assertCreated();
    $this->postJson('/api/v1/issues/WEB-2/links', ['type' => $type, 'to' => 'WEB-3'])->assertCreated();

    $this->postJson('/api/v1/issues/WEB-3/links', ['type' => $type, 'to' => 'WEB-1'])->assertUnprocessable()
        ->assertJsonPath('errors.to.0', "That would make a loop: WEB-1 → WEB-2 → WEB-3 → WEB-1. \"{$type}\" links can't loop.");
    // The two-issue loop, sent with the reverse name.
    $reverse = LinkType::from($type)->reverse();
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => $reverse, 'to' => 'WEB-2'])->assertUnprocessable()
        ->assertJsonPath('errors.to.0', "That would make a loop: WEB-1 → WEB-2 → WEB-1. \"{$type}\" links can't loop.");

    expect(IssueLink::count())->toBe(2);
})->with(['blocks', 'precedes']);

test('a loop through a deleted issue is refused, so restoring it can\'t complete one', function () {
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'WEB-2'])->assertCreated();
    $this->postJson('/api/v1/issues/WEB-2/links', ['type' => 'blocks', 'to' => 'WEB-3'])->assertCreated();
    app(IssueService::class)->delete(web(2), $this->admin);

    $this->postJson('/api/v1/issues/WEB-3/links', ['type' => 'blocks', 'to' => 'WEB-1'])->assertUnprocessable()
        ->assertJsonPath('errors.to.0', 'That would make a loop: WEB-1 → WEB-2 → WEB-3 → WEB-1. "blocks" links can\'t loop.');
});

test('relates to may loop', function () {
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'relates to', 'to' => 'WEB-2'])->assertCreated();
    $this->postJson('/api/v1/issues/WEB-2/links', ['type' => 'relates to', 'to' => 'WEB-3'])->assertCreated();
    $this->postJson('/api/v1/issues/WEB-3/links', ['type' => 'relates to', 'to' => 'WEB-1'])->assertCreated();
});

test('adding and removing a link is logged on both issues with who, agent and via', function () {
    $agentUser = apiAs($this->admin);
    $agent = $agentUser->agent();

    PmServer::tool(LinkIssues::class, ['from' => 'WEB-12', 'type' => 'blocks', 'to' => 'WEB-2'])->assertHasErrors(['Issue WEB-12 was not found']);
    PmServer::tool(LinkIssues::class, ['from' => 'WEB-2', 'type' => 'is blocked by', 'to' => 'WEB-1'])->assertOk()->assertSee(['WEB-1 blocks WEB-2', '"from":"WEB-1"']);
    PmServer::tool(UnlinkIssues::class, ['from' => 'WEB-1', 'type' => 'blocks', 'to' => 'WEB-2'])->assertOk()->assertSee('Unlinked: WEB-1 blocks WEB-2.');
    PmServer::tool(UnlinkIssues::class, ['from' => 'WEB-1', 'type' => 'blocks', 'to' => 'WEB-2'])->assertHasErrors(['No link "WEB-1 blocks WEB-2" to remove.']);

    $log = fn (int $n) => Activity::where('issue_id', web($n)->id)->whereIn('action', ['linked', 'unlinked'])->orderBy('id')->get()
        ->map(fn ($a) => [$a->action, $a->changes['link']['from'] ?? null, $a->changes['link']['to'] ?? null, $a->user_id, $a->agent_id, $a->via])->all();
    expect($log(1))->toBe([
        ['linked', null, 'blocks WEB-2', $this->admin->id, $agent->id, 'mcp'],
        ['unlinked', 'blocks WEB-2', null, $this->admin->id, $agent->id, 'mcp'],
    ])->and($log(2))->toBe([
        ['linked', null, 'is blocked by WEB-1', $this->admin->id, $agent->id, 'mcp'],
        ['unlinked', 'is blocked by WEB-1', null, $this->admin->id, $agent->id, 'mcp'],
    ])->and(IssueLink::count())->toBe(0);
});

test('a link row records who made it', function () {
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'WEB-2'])->assertCreated();

    expect(IssueLink::sole())->created_by->toBe($this->admin->id)->agent_id->toBeNull()->via->toBe('web');
});

test('get_issue and the REST issue group links by type with each issue\'s category, across projects in the workspace', function () {
    $api = makeProject($this->admin, 'API');
    makeIssue($api, $this->admin, ['title' => 'Api one', 'status' => 'In Progress']);
    $this->postJson('/api/v1/issues/API-1/links', ['type' => 'blocks', 'to' => 'WEB-2'])->assertCreated();
    $this->postJson('/api/v1/issues/WEB-2/links', ['type' => 'relates to', 'to' => 'WEB-3'])->assertCreated();
    $this->postJson('/api/v1/issues/WEB-2/links', ['type' => 'precedes', 'to' => 'WEB-4'])->assertCreated();

    $links = $this->getJson('/api/v1/issues/WEB-2')->assertOk()->assertJsonPath('data.blocked', true)->json('data.links');
    expect(array_keys($links))->toBe(['is blocked by', 'precedes', 'relates to'])
        ->and($links['is blocked by'])->toEqual([['key' => 'API-1', 'title' => 'Api one', 'type' => 'task', 'status' => 'In Progress', 'category' => 'in_progress', 'status_color' => null]])
        ->and($links['precedes'][0]['key'])->toBe('WEB-4')
        ->and($links['relates to'][0]['category'])->toBe('to_do');

    apiAs($this->admin);
    PmServer::tool(GetIssue::class, ['issue' => 'WEB-3'])->assertOk()->assertSee('"links":{"relates to":[{"key":"WEB-2","title":"Issue 2","type":"task","status":"To Do","category":"to_do"');
    $this->getJson('/api/v1/issues/WEB-5')->assertOk()->assertJsonPath('data.links', [])->assertJsonPath('data.blocked', false);
});

test('deleting an issue hides its links, restoring shows them, issues:purge removes the rows', function () {
    $this->postJson('/api/v1/issues/WEB-2/links', ['type' => 'blocks', 'to' => 'WEB-1'])->assertCreated();
    $issues = app(IssueService::class);

    $issues->delete(web(2), $this->admin);
    $this->getJson('/api/v1/issues/WEB-1')->assertJsonPath('data.links', [])->assertJsonPath('data.blocked', false);

    $issues->restore(Issue::onlyTrashed()->where('number', 2)->with('project')->sole(), $this->admin);
    $this->getJson('/api/v1/issues/WEB-1')->assertJsonPath('data.links.is blocked by.0.key', 'WEB-2')->assertJsonPath('data.blocked', true);

    $issues->delete(web(2), $this->admin);
    $this->travel(31)->days();
    $this->artisan('issues:purge')->assertSuccessful();
    expect(IssueLink::count())->toBe(0);
});

test('the Blocked flag on lists clears when the blocker resolves or is deleted', function () {
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'WEB-2'])->assertCreated();
    $blocked = fn () => collect($this->getJson('/api/v1/projects/WEB/issues')->assertOk()->json('data'))->pluck('blocked', 'key')->all();
    $board = fn () => collect($this->getJson('/api/v1/projects/WEB/board')->json('data.columns'))->flatMap(fn ($c) => $c['issues'])->pluck('blocked', 'key')->all();

    expect($blocked())->toMatchArray(['WEB-2' => true, 'WEB-1' => false])->and($board())->toMatchArray(['WEB-2' => true]);

    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertOk();
    expect($blocked()['WEB-2'])->toBeFalse()->and($board()['WEB-2'])->toBeFalse();

    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'To Do'])->assertOk();
    expect($blocked()['WEB-2'])->toBeTrue();
    app(IssueService::class)->delete(web(1), $this->admin);
    expect($blocked()['WEB-2'])->toBeFalse();
});

test('blocked and blocked_by filter REST, MCP search_issues and the web list', function () {
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'WEB-2'])->assertCreated();
    $this->postJson('/api/v1/issues/WEB-3/links', ['type' => 'blocks', 'to' => 'WEB-4'])->assertCreated();
    $this->postJson('/api/v1/issues/WEB-3/move', ['status' => 'Done'])->assertOk();
    $keys = fn (string $query) => collect($this->getJson("/api/v1/issues?{$query}")->assertOk()->json('data'))->pluck('key')->sort()->values()->all();

    // blocked: an unresolved blocker; blocked_by: the issues a key blocks, resolved or not.
    expect($keys('blocked=1'))->toBe(['WEB-2'])
        ->and($keys('blocked=true'))->toBe(['WEB-2'])
        ->and($keys('blocked=0'))->toBe(['WEB-1', 'WEB-3', 'WEB-4', 'WEB-5'])
        ->and($keys('blocked_by=web-3'))->toBe(['WEB-4'])
        ->and($keys('blocked_by=WEB-9'))->toBe([]);
    $this->getJson('/api/v1/projects/WEB/issues?blocked=maybe')->assertUnprocessable()->assertJsonValidationErrors('blocked');

    apiAs($this->admin);
    PmServer::tool(SearchIssues::class, ['blocked' => true])->assertOk()->assertSee(['1 of 1', 'WEB-2']);
    PmServer::tool(SearchIssues::class, ['blocked_by' => 'WEB-3'])->assertOk()->assertSee(['1 of 1', 'WEB-4']);

    $this->actingAs($this->admin)->get(w('/projects/WEB/issues?blocked=1'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('issues.data', 1)->where('issues.data.0.key', 'WEB-2')->where('issues.data.0.blocked', true));
});

test('Close as Duplicate creates a duplicates link', function () {
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-3'])->assertOk();

    expect(linkRow(web(1), web(3))?->type)->toBe(LinkType::Duplicates)
        ->and(Activity::where('issue_id', web(3)->id)->where('action', 'linked')->sole()->changes['link']['to'])->toBe('is duplicated by WEB-1');

    // Closing it again the same way (Undo of a reopen does) adds no second link.
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'To Do'])->assertOk();
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-3'])->assertOk();
    expect(IssueLink::count())->toBe(1);
});

test('closing as a duplicate of another original replaces the duplicates link, logged on both pairs; by hand too', function () {
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-2'])->assertOk();
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 2, 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-3'])->assertOk();

    expect(IssueLink::where('source_issue_id', web(1)->id)->pluck('target_issue_id')->all())->toBe([web(3)->id]);
    $logged = fn (int $n) => Activity::where('issue_id', web($n)->id)->whereIn('action', ['linked', 'unlinked'])->orderBy('id')->get()
        ->map(fn ($a) => $a->action.' '.($a->changes['link']['to'] ?? $a->changes['link']['from']))->all();
    expect($logged(1))->toBe(['linked duplicates WEB-2', 'unlinked duplicates WEB-2', 'linked duplicates WEB-3'])
        ->and($logged(2))->toBe(['linked is duplicated by WEB-1', 'unlinked is duplicated by WEB-1'])
        ->and($logged(3))->toBe(['linked is duplicated by WEB-1']);

    // By hand too: an issue duplicates one original.
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'duplicates', 'to' => 'WEB-4'])->assertCreated();
    expect(IssueLink::where('source_issue_id', web(1)->id)->pluck('target_issue_id')->all())->toBe([web(4)->id]);
});

test('a failing duplicates link after the close is reported and the close still succeeds', function () {
    Exceptions::fake();
    IssueLink::creating(fn () => throw new RuntimeException('link insert failed'));

    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-3'])->assertOk()
        ->assertJsonPath('data.resolution', 'duplicate');

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'link insert failed');
    expect(web(1)->resolution->value)->toBe('duplicate')->and(IssueLink::count())->toBe(0);
});

test('web: Undo of a move off Duplicate restores duplicate_of from the link', function () {
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-3'])->assertOk();
    $this->actingAs($this->admin);

    $this->post(w('/issues/WEB-1/move'), ['status' => 'In Progress'])->assertSessionHasNoErrors();
    $undo = session('undo');
    expect($undo['data'])->toMatchArray(['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-3']);

    $this->post($undo['url'], $undo['data'])->assertSessionHasNoErrors();
    expect(web(1)->resolution->value)->toBe('duplicate')->and(IssueLink::count())->toBe(1);
});

test('web: adding and removing a link flash Undo', function () {
    $this->actingAs($this->admin);

    $this->post(w('/issues/WEB-2/links'), ['type' => 'is blocked by', 'to' => 'WEB-1'])->assertSessionHasNoErrors();
    $undo = session('undo');
    expect($undo)->toMatchArray(['message' => 'WEB-1 blocks WEB-2', 'method' => 'delete'])
        ->and($undo['data'])->toMatchArray(['type' => 'is blocked by', 'to' => 'WEB-1'])
        ->and(IssueLink::count())->toBe(1);

    $this->delete($undo['url'], $undo['data'])->assertSessionHasNoErrors();
    expect(IssueLink::count())->toBe(0)->and(session('undo'))->toBeNull();

    $this->post(w('/issues/WEB-2/links'), ['type' => 'is blocked by', 'to' => 'WEB-1']);
    $this->delete(w('/issues/WEB-1/links'), ['type' => 'blocks', 'to' => 'WEB-2'])->assertSessionHasNoErrors();
    $undo = session('undo');
    expect($undo)->toMatchArray(['message' => 'Link removed', 'method' => 'post'])->and(IssueLink::count())->toBe(0);

    $this->post($undo['url'], $undo['data'])->assertSessionHasNoErrors();
    expect(linkRow(web(1), web(2))->type)->toBe(LinkType::Blocks);
});

test('the web issue page shows links and Blocked', function () {
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'WEB-2'])->assertCreated();

    $this->actingAs($this->admin)->get(w('/issues/WEB-2'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('issue.blocked', true)->where('issue.links.is blocked by.0.key', 'WEB-1'));
});
