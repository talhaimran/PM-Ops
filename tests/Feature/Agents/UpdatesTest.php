<?php

use App\Enums\AgentLevel;
use App\Enums\ApprovalStatus;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\GetUpdates;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\Auth;

// A3/A-L3: what happened to this agent's approval requests since a cursor. Only this agent's, in order.

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Talha Imran']);
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->web = makeProject($this->owner, 'WEB');
    foreach (range(1, 5) as $n) {
        makeIssue($this->web, $this->owner, ['title' => "Issue {$n}", 'status' => 'In Review']);
    }
    [$this->agent, $this->token] = makeAgent($this->owner, AgentLevel::Write, name: 'mine');
    [$this->other, $this->otherToken] = makeAgent($this->owner, AgentLevel::Write, name: 'other');
});

/** A pending request from $agent for WEB-$n, made over REST like a real agent. */
function askDone(string $token, int $n): int
{
    return asToken($token)->postJson("/api/v1/issues/WEB-{$n}/move", ['status' => 'Done'])->assertStatus(202)->json('approval_request.id');
}

/** Polls a second later: updates are only read once their second is complete. */
function updatesFor(string $token, string $query = '')
{
    test()->travel(1)->seconds();

    return asToken($token)->getJson('/api/v1/me/updates'.$query)->assertOk();
}

test('an agent hears how its requests ended, oldest first, with who decided and why', function () {
    $approved = askDone($this->token, 1);
    $rejected = askDone($this->token, 2);
    $this->travel(1)->minute();
    app(ApprovalService::class)->approve(ApprovalRequest::findOrFail($approved), $this->owner);
    $this->travel(1)->minute();
    app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($rejected), $this->owner, 'Not yet');
    $pending = askDone($this->token, 3);

    $updates = updatesFor($this->token)->json('data');

    expect($updates)->toHaveCount(2)
        ->and($updates[0])->toMatchArray(['kind' => 'approval.approved', 'approval_request_id' => $approved, 'issue' => 'WEB-1', 'to' => 'Done', 'decided_by' => 'Talha Imran', 'note' => null])
        ->and($updates[1])->toMatchArray(['kind' => 'approval.rejected', 'approval_request_id' => $rejected, 'issue' => 'WEB-2', 'decided_by' => 'Talha Imran', 'note' => 'Not yet'])
        ->and($updates[0]['id'])->not->toBeNull()
        ->and($updates[0]['at'])->toBeString()
        ->and(collect($updates)->pluck('approval_request_id'))->not->toContain($pending);
});

test('expired and stale requests are reported too, with no decider', function () {
    $expired = askDone($this->token, 1);
    $stale = askDone($this->token, 2);
    ApprovalRequest::whereKey($expired)->update(['expires_at' => now()->subMinute()]);
    app(ApprovalService::class)->expireDue();
    ApprovalRequest::whereKey($stale)->update(['status' => ApprovalStatus::Stale, 'note' => 'WEB-2 was deleted.', 'updated_at' => now()]);

    $updates = collect(updatesFor($this->token)->json('data'))->keyBy('approval_request_id');

    expect($updates[$expired]['kind'])->toBe('approval.expired')->and($updates[$expired]['decided_by'])->toBeNull()
        ->and($updates[$stale]['kind'])->toBe('approval.stale')->and($updates[$stale]['note'])->toBe('WEB-2 was deleted.');
});

test('the cursor continues after the last update, and an empty answer keeps it', function () {
    $ids = collect([1, 2, 3])->map(fn ($n) => askDone($this->token, $n));
    foreach ($ids as $i => $id) {
        $this->travel(1)->minute();
        app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($id), $this->owner, "no {$i}");
    }

    $first = updatesFor($this->token, '?per_page=2')->json();
    expect(collect($first['data'])->pluck('approval_request_id')->all())->toBe([$ids[0], $ids[1]])->and($first['meta']['next_cursor'])->toBeString();

    $second = updatesFor($this->token, '?per_page=2&cursor='.$first['meta']['next_cursor'])->json();
    expect(collect($second['data'])->pluck('approval_request_id')->all())->toBe([$ids[2]]);

    $none = updatesFor($this->token, '?cursor='.$second['meta']['next_cursor'])->json();
    expect($none['data'])->toBe([])->and($none['meta']['next_cursor'])->toBe($second['meta']['next_cursor']);

    // A decision made later shows up after that cursor.
    $this->travel(1)->minute();
    $late = askDone($this->token, 4);
    app(ApprovalService::class)->approve(ApprovalRequest::findOrFail($late), $this->owner);
    expect(collect(updatesFor($this->token, '?cursor='.$second['meta']['next_cursor'])->json('data'))->pluck('approval_request_id')->all())->toBe([$late]);
});

test('requests decided in the same second are ordered by id and none repeat across pages', function () {
    $ids = collect([1, 2, 3])->map(fn ($n) => askDone($this->token, $n));
    $ids->each(fn ($id) => app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($id), $this->owner, null));
    ApprovalRequest::query()->update(['updated_at' => now()->startOfSecond()]);

    $seen = [];
    $cursor = null;
    do {
        $page = updatesFor($this->token, '?per_page=1'.($cursor ? '&cursor='.$cursor : ''))->json();
        $seen = [...$seen, ...collect($page['data'])->pluck('approval_request_id')->all()];
        $cursor = $page['data'] ? $page['meta']['next_cursor'] : null;
    } while ($cursor);

    expect($seen)->toBe($ids->all());
});

test('another agent of the same owner sees none of it', function () {
    $id = askDone($this->token, 1);
    app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($id), $this->owner, null);

    expect(updatesFor($this->otherToken)->json('data'))->toBe([])
        ->and(updatesFor($this->token)->json('data'))->toHaveCount(1);
});

test('requests in projects the agent can no longer reach are left out', function () {
    $api = makeProject($this->owner, 'API');
    [$scoped, $token] = makeAgent($this->owner, AgentLevel::Write, ['WEB'], 'scoped');
    $id = askDone($token, 1);
    app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($id), $this->owner, null);
    expect(updatesFor($token)->json('data'))->toHaveCount(1);

    $scoped->projects()->sync([$api->id]);

    expect(updatesFor($token)->json('data'))->toBe([]);
});

test('a bad cursor is a 422 that says what to do', function () {
    asToken($this->token)->getJson('/api/v1/me/updates?cursor=garbage')->assertUnprocessable()->assertJsonValidationErrors('cursor');
});

test('get_updates over MCP returns {updates, next_cursor} for the calling agent only', function () {
    $id = askDone($this->token, 1);
    app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($id), $this->owner, 'Needs tests');

    $this->travel(1)->seconds();
    Auth::setUser($this->owner->fresh()->withAccessToken($this->agent->token()->sole()));
    PmServer::tool(GetUpdates::class)->assertOk()->assertSee(['"kind":"approval.rejected"', '"issue":"WEB-1"', '"note":"Needs tests"', '"next_cursor":"']);

    Auth::setUser($this->owner->fresh()->withAccessToken($this->other->token()->sole()));
    PmServer::tool(GetUpdates::class)->assertOk()->assertSee(['"updates":[]'])->assertDontSee('WEB-1');
});

test('an outcome is only read once its second is complete, so a late-decided lower id is never skipped', function () {
    $this->freezeTime();
    $high = askDone($this->token, 2);
    $low = askDone($this->token, 1);
    [$low, $high] = [min($low, $high), max($low, $high)];
    app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($high), $this->owner, null);

    // (a) decided "now": not returned yet, and no cursor is handed out for the open second.
    $first = asToken($this->token)->getJson('/api/v1/me/updates')->assertOk()->json();
    expect($first['data'])->toBe([])->and($first['meta']['next_cursor'])->toBeNull();

    // (b) the lower id is decided in the same second, after that poll.
    app(ApprovalService::class)->reject(ApprovalRequest::findOrFail($low), $this->owner, null);
    $this->travel(1)->seconds();

    $ids = collect(asToken($this->token)->getJson('/api/v1/me/updates')->json('data'))->pluck('approval_request_id')->all();
    expect($ids)->toBe([$low, $high]);
});
