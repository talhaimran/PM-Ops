<?php

use App\Models\Comment;
use App\Models\User;
use App\Services\CommentService;
use App\Services\IssueService;
use Illuminate\Validation\ValidationException;

/* Spec 7.11: comments carry a version too; an edit sends the version it read and a stale one stops the save. */

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->project = makeProject($this->admin, 'WEB');
    $this->issue = makeIssue($this->project, $this->admin);
});

test('an edit with the version you read saves and returns the next version; a no-op does not bump', function () {
    [, $token] = makeAgent($this->admin);
    $id = asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => 'First'])->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

    asToken($token)->patchJson("/api/v1/comments/{$id}", ['body' => 'Second', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
    asToken($token)->patchJson("/api/v1/comments/{$id}", ['body' => 'Second', 'version' => 2])->assertOk()->assertJsonPath('data.version', 2);

    expect(Comment::sole())->body->toBe('Second')->version->toBe(2);
});

test('a stale version is a 409 with the current body, who and when, and changes nothing', function () {
    [, $token] = makeAgent($this->admin, name: 'Helper');
    $id = asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => 'First'])->json('data.id');
    asToken($token)->patchJson("/api/v1/comments/{$id}", ['body' => 'Theirs', 'version' => 1])->assertOk();

    asToken($token)->patchJson("/api/v1/comments/{$id}", ['body' => 'Mine', 'version' => 1])
        ->assertStatus(409)
        ->assertJsonPath('error', 'conflict')
        ->assertJsonPath('message', "Comment {$id} changed since version 1. Read it again and resend.")
        ->assertJsonPath('current_version', 2)
        ->assertJsonPath('comment.body', 'Theirs')
        ->assertJsonPath('comment.author.name', 'Ada Admin')
        ->assertJsonPath('comment.agent.name', 'Helper')
        ->assertJsonStructure(['comment' => ['updated_at']]);

    expect(Comment::sole())->body->toBe('Theirs')->version->toBe(2);
});

test('a missing version is a 422 on REST, MCP and web', function () {
    [, $token] = makeAgent($this->admin);
    $id = asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => 'First'])->json('data.id');
    $message = 'Send the version you read (the `version` field of get_issue).';

    asToken($token)->patchJson("/api/v1/comments/{$id}", ['body' => 'x'])->assertUnprocessable()->assertJsonPath('errors.version.0', $message);

    // MCP as the same agent: only the hand that wrote a comment may edit it.
    asToken($token, ['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'update_comment', 'arguments' => ['comment_id' => $id, 'body' => 'x']]])
        ->assertJsonPath('result.isError', true)->assertSee($message);

    $typed = app(CommentService::class)->add($this->issue, $this->admin, 'Typed');
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($this->admin)->patch(w("/comments/{$typed->id}"), ['body' => 'x'])->assertSessionHasErrors(['version' => $message]);

    expect(Comment::pluck('body')->all())->toBe(['First', 'Typed']);
});

test('web: a stale edit flashes the conflict with both texts; overwrite with the current version saves', function () {
    $comment = app(CommentService::class)->add($this->issue, $this->admin, 'First');
    app(CommentService::class)->update($comment, 'Theirs', 1);

    $this->actingAs($this->admin)->patch(w("/comments/{$comment->id}"), ['body' => 'Mine', 'version' => 1])
        ->assertSessionHasErrors(['version' => "Comment {$comment->id} changed since version 1. Read it again and resend."]);
    expect(session('conflict'))->toMatchArray(['key' => 'WEB-1', 'version' => 2, 'yours' => ['body' => 'Mine'], 'theirs' => ['body' => 'Theirs'], 'by' => 'Ada Admin'])
        ->and(Comment::sole()->body)->toBe('Theirs');

    $this->patch(w("/comments/{$comment->id}"), ['body' => 'Mine', 'version' => 2, 'overwrite' => true])->assertSessionHasNoErrors();
    expect(Comment::sole())->body->toBe('Mine')->version->toBe(3);
});

test('an edit that loaded the comment before someone deleted it is a 404 and changes nothing', function () {
    $comment = app(CommentService::class)->add($this->issue, $this->admin, 'First');
    // The race: the request resolved the comment, then a delete committed before the update's lock.
    $loaded = app(CommentService::class)->resolve($this->admin, $comment->id);
    app(CommentService::class)->delete(Comment::find($comment->id));

    expect(fn () => app(CommentService::class)->update($loaded, 'Late', 1))
        ->toThrow(ValidationException::class, "Comment {$comment->id} was not found. Get the issue to see its comment ids.");
    expect(Comment::withTrashed()->sole())->body->toBe('First')->version->toBe(1);
});

test('an edit that loaded the comment before someone deleted its issue is a 404 and changes nothing', function () {
    $comment = app(CommentService::class)->add($this->issue, $this->admin, 'First');
    $loaded = app(CommentService::class)->resolve($this->admin, $comment->id);
    app(IssueService::class)->delete($this->issue->fresh(), $this->admin);

    expect(fn () => app(CommentService::class)->update($loaded, 'Late', 1))
        ->toThrow(ValidationException::class, "Comment {$comment->id} was not found. Get the issue to see its comment ids.");
    expect(Comment::withTrashed()->sole())->body->toBe('First')->version->toBe(1);
});
