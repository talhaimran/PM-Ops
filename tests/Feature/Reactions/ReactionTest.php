<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Events\ReactionChanged;
use App\Models\Activity;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\CommentService;
use Illuminate\Support\Facades\Event;

// Spec 7.11 (3-R13): reactions on comments.

const SIX = ['👍', '✅', '👀', '🎉', '❤️', '😄'];

beforeEach(function () {
    $this->ann = User::factory()->create(['name' => 'Ann Admin', 'email' => 'ann@example.com']);
    $this->project = makeProject($this->ann, 'WEB');
    $this->bob = addMember($this->project, User::factory()->create(['name' => 'Bob Builder', 'email' => 'bob@example.com']), ProjectRole::Member);
    $this->vic = addMember($this->project, User::factory()->create(['email' => 'vic@example.com']), ProjectRole::Viewer);
    $this->issue = makeIssue($this->project, $this->ann);
    $this->comment = app(CommentService::class)->add($this->issue, $this->ann, 'Hello');
    $this->url = "/api/v1/comments/{$this->comment->id}/reactions";
    InboxItem::query()->delete();
});

test('each of the six can be added and removed', function () {
    apiAsPerson($this->bob);
    foreach (SIX as $emoji) {
        $this->postJson($this->url, ['emoji' => $emoji])->assertOk();
    }
    expect($this->getJson('/api/v1/issues/WEB-1')->json('data.comments.0.reactions'))->toHaveCount(6);
    foreach (SIX as $emoji) {
        $this->deleteJson($this->url, ['emoji' => $emoji])->assertOk();
    }
    expect($this->getJson('/api/v1/issues/WEB-1')->json('data.comments.0.reactions'))->toBe([]);
});

test('a seventh emoji is a 422 listing the six', function () {
    apiAsPerson($this->bob);
    $res = $this->postJson($this->url, ['emoji' => '🔥'])->assertStatus(422);

    expect($res->json('errors.emoji.0'))->toContain(...SIX);
});

test('a person and their agent react separately, and a repeat is idempotent', function () {
    apiAsPerson($this->bob);
    $this->postJson($this->url, ['emoji' => '👍'])->assertOk();
    $this->postJson($this->url, ['emoji' => '👍'])->assertOk();
    [, $token] = makeAgent($this->bob, AgentLevel::Write);
    $res = asToken($token)->postJson($this->url, ['emoji' => '👍'])->assertOk();

    $reaction = $res->json('data.reactions.0');
    expect($reaction['emoji'])->toBe('👍')->and($reaction['count'])->toBe(2)->and($reaction['people'])->toBe(['Bob Builder', "Bob's agent"]);
});

test('reacting changes no version, no timestamp, no activity and notifies nobody', function () {
    Event::fake([ReactionChanged::class]);
    $before = [$this->comment->fresh()->only(['version', 'updated_at']), $this->issue->fresh()->only(['version', 'updated_at']), Activity::count()];
    $this->travel(10)->seconds();

    apiAsPerson($this->bob);
    $this->postJson($this->url, ['emoji' => '🎉'])->assertOk();
    $this->postJson($this->url, ['emoji' => '🎉'])->assertOk();
    $this->deleteJson($this->url, ['emoji' => '🎉'])->assertOk();

    expect([$this->comment->fresh()->only(['version', 'updated_at']), $this->issue->fresh()->only(['version', 'updated_at']), Activity::count()])->toEqual($before)
        ->and(InboxItem::count())->toBe(0);
    // Add once, the repeat is not a change, then the removal.
    Event::assertDispatchedTimes(ReactionChanged::class, 2);
});

test('a viewer and a read-only agent get 403', function () {
    apiAsPerson($this->vic);
    $this->postJson($this->url, ['emoji' => '👍'])->assertForbidden();
    $this->deleteJson($this->url, ['emoji' => '👍'])->assertForbidden();

    [, $token] = makeAgent($this->bob, AgentLevel::Read);
    asToken($token)->postJson($this->url, ['emoji' => '👍'])->assertForbidden();
});

test('MCP add_reaction and remove_reaction', function () {
    [, $token] = makeAgent($this->bob, AgentLevel::Write);
    $json = mcpJson($token, 'add_reaction', ['comment_id' => $this->comment->id, 'emoji' => '👀']);
    expect($json['reactions'][0]['emoji'])->toBe('👀')->and($json['reactions'][0]['people'])->toBe(["Bob's agent"]);

    expect(mcpJson($token, 'remove_reaction', ['comment_id' => $this->comment->id, 'emoji' => '👀'])['reactions'])->toBe([]);
});

test('web: react and unreact redirect back', function () {
    $this->actingAs($this->bob)->post(w("/comments/{$this->comment->id}/reactions"), ['emoji' => '❤️'])->assertRedirect();
    expect($this->comment->reactions()->count())->toBe(1);
    $this->actingAs($this->bob)->delete(w("/comments/{$this->comment->id}/reactions"), ['emoji' => '❤️'])->assertRedirect();
    expect($this->comment->reactions()->count())->toBe(0);
});
