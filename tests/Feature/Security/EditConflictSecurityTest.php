<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Models\Activity;
use App\Models\ApprovalRequest;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\BoardService;
use App\Services\CommentService;
use App\Services\IssueService;
use Illuminate\Validation\ValidationException;

// Spec 7.11: a conflict tells what changed, so it must never tell it to someone who couldn't read or write the issue.

beforeEach(function () {
    $this->ann = User::factory()->create(['name' => 'Ann Owner']);
    $this->web = makeProject($this->ann, 'WEB');
    $this->issue = makeIssue($this->web, $this->ann, ['title' => 'B-SECRET original']);
    app(IssueService::class)->update($this->issue, $this->ann, ['title' => 'B-SECRET edited']);
});

test('a stale edit of an issue or comment the caller cannot see is the usual 404, not a 409', function () {
    $comment = app(CommentService::class)->add($this->issue, $this->ann, 'B-SECRET comment');
    app(CommentService::class)->update($comment, 'B-SECRET comment edited', 1);

    // Same workspace, not in WEB (a plain Member sees only their own projects); and another workspace.
    $bob = User::factory()->create();
    makeProject($bob, 'OPS');
    asWorkspaceMember($bob);
    [, $bobToken] = makeAgent($bob);
    $zed = User::factory()->create();
    makeWorkspace($zed, 'elsewhere');
    makeProject($zed, 'ZED');
    [, $zedToken] = makeAgent($zed);

    foreach ([$bobToken, $zedToken] as $token) {
        foreach ([
            asToken($token)->patchJson('/api/v1/issues/WEB-1', ['title' => 'x', 'version' => 1]),
            asToken($token)->patchJson('/api/v1/issues/bulk', ['updates' => [['key' => 'WEB-1', 'title' => 'x', 'version' => 1]]]),
            asToken($token)->patchJson("/api/v1/comments/{$comment->id}", ['body' => 'x', 'version' => 1]),
        ] as $response) {
            expect($response->status())->toBeIn([404, 422])
                ->and($response->content())->not->toContain('B-SECRET')->not->toContain('current_version');
        }
    }
    expect(Issue::sole()->title)->toBe('B-SECRET edited')->and(Comment::sole()->body)->toBe('B-SECRET comment edited');
});

test('the changes list shows only this issue\'s changes, never another project\'s', function () {
    $ops = makeProject($this->ann, 'OPS');
    $other = makeIssue($ops, $this->ann, ['title' => 'B-SECRET ops']);
    // Version 2 and 3 in OPS-1, so "issue_version > 1" alone would match them.
    app(IssueService::class)->update($other, $this->ann, ['title' => 'B-SECRET ops 2']);
    app(IssueService::class)->update($other->fresh(), $this->ann, ['priority' => 'high']);
    [, $token] = makeAgent($this->ann);

    $response = asToken($token)->patchJson('/api/v1/issues/WEB-1', ['description' => 'x', 'version' => 1])->assertStatus(409);

    expect($response->json('changes'))->toHaveCount(1)
        ->and($response->json('changes.0'))->toMatchArray(['field' => 'title', 'to' => 'B-SECRET edited'])
        ->and($response->content())->not->toContain('B-SECRET ops');
});

test('a Viewer and a read-only agent get their 403 before any conflict detail', function () {
    $viewer = addMember($this->web, User::factory()->create(), ProjectRole::Viewer);
    $this->actingAs($viewer)->patch(w('/issues/WEB-1'), ['title' => 'x', 'version' => 1])->assertForbidden();
    expect(session('conflict'))->toBeNull();

    [, $token] = makeAgent($this->ann, AgentLevel::Read);
    $response = asToken($token)->patchJson('/api/v1/issues/WEB-1', ['title' => 'x', 'version' => 1])->assertForbidden();
    expect($response->content())->not->toContain('B-SECRET')->not->toContain('current_version');

    $mcp = asToken($token, ['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'update_issue', 'arguments' => ['issue' => 'WEB-1', 'title' => 'x', 'version' => 1]]]);
    expect($mcp->content())->not->toContain('B-SECRET')->not->toContain('current_version');
    expect(Issue::sole()->title)->toBe('B-SECRET edited');
});

test('overwrite is web-only: REST and MCP ignore it', function () {
    [, $token] = makeAgent($this->ann);

    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['title' => 'Forced', 'version' => 1, 'overwrite' => true])->assertStatus(409);
    $mcp = asToken($token, ['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'update_issue', 'arguments' => ['issue' => 'WEB-1', 'title' => 'Forced', 'version' => 1, 'overwrite' => true]]])
        ->assertJsonPath('result.isError', true);
    expect($mcp->content())->toContain('WEB-1 changed since version 1')->toContain('current_version')
        ->and(Issue::sole()->title)->toBe('B-SECRET edited');

    // With the current version it saves as a plain update, never logged as an overwrite.
    asToken($token)->patchJson('/api/v1/issues/WEB-1', ['title' => 'Saved', 'version' => 2, 'overwrite' => true])->assertOk();
    expect(Activity::latest('id')->first()->action)->toBe('updated');
});

test('an agent asking for Done again keeps the first version, so a person\'s change since still needs the second confirm', function () {
    [, $token] = makeAgent($this->ann, AgentLevel::Write);
    $ask = fn () => asToken($token)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done'])->assertStatus(202);
    $ask();
    // A person reopens the work; the agent then asks again (scan finding F1, PR 34).
    app(BoardService::class)->move($this->issue->fresh(), $this->ann, ['status' => 'In Progress']);
    $ask();

    expect(fn () => app(ApprovalService::class)->approve(ApprovalRequest::sole(), $this->ann))
        ->toThrow(ValidationException::class, 'Ann Owner moved WEB-1');
    expect($this->issue->fresh()->status->name)->toBe('In Progress');
});
