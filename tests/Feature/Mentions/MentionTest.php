<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Http\Resources\InboxItemResource;
use App\Models\InboxItem;
use App\Models\Mention;
use App\Models\User;
use App\Services\CommentService;
use App\Services\IssueService;
use App\Services\MentionService;
use App\Services\WatcherService;
use Illuminate\Support\Collection;

// Spec 7.10 (3-R9..R11): @email mentions in comments and descriptions.

beforeEach(function () {
    $this->ann = User::factory()->create(['name' => 'Ann Admin', 'email' => 'ann@example.com']);
    $this->project = makeProject($this->ann, 'WEB');
    $this->sam = addMember($this->project, User::factory()->create(['name' => 'Sam Smith', 'email' => 'sam@example.com']), ProjectRole::Member);
    $this->alex = addMember($this->project, User::factory()->create(['name' => 'Alex Kim', 'email' => 'alex@example.com']), ProjectRole::Member);
    $this->issue = makeIssue($this->project, $this->ann, ['title' => 'First', 'assignee' => 'sam@example.com']);
    InboxItem::query()->delete();
});

function mentionRows(User $user): Collection
{
    return InboxItem::where('user_id', $user->id)->where('event', 'mention')->get();
}

test('a comment mention makes one row, notifies at once and auto-watches', function () {
    app(CommentService::class)->add($this->issue, $this->ann, 'Please look @sam@example.com and @sam@example.com');

    expect(Mention::where('mentioned_user_id', $this->sam->id)->count())->toBe(1)
        ->and(mentionRows($this->sam))->toHaveCount(1)
        ->and(app(WatcherService::class)->isWatching($this->issue, $this->sam))->toBeTrue();
    // Mentions are never batched: a second one is its own row.
    app(CommentService::class)->add($this->issue, $this->ann, 'again @sam@example.com');
    expect(mentionRows($this->sam))->toHaveCount(2);
});

test('editing a comment notifies only the newly added mention', function () {
    $comment = app(CommentService::class)->add($this->issue, $this->ann, 'Hi @sam@example.com');
    app(CommentService::class)->update($comment, 'Hi @sam@example.com and @alex@example.com', $comment->version);

    expect(mentionRows($this->sam))->toHaveCount(1)->and(mentionRows($this->alex))->toHaveCount(1);
});

test('a non-member email saves with a warning and no notification, on REST and MCP', function () {
    apiAs($this->ann, AgentLevel::Write);
    $res = $this->postJson('/api/v1/issues/WEB-1/comments', ['body' => 'cc @stranger@example.com'])->assertCreated();

    expect($res->json('data.warnings.0.code'))->toBe('mention_not_member')->and($res->json('data.warnings.0.email'))->toBe('stranger@example.com')
        ->and(Mention::count())->toBe(0)->and(InboxItem::where('event', 'mention')->count())->toBe(0);

    [, $token] = makeAgent($this->ann, AgentLevel::Write);
    $json = mcpJson($token, 'add_comment', ['issue' => 'WEB-1', 'body' => 'cc @stranger@example.com']);
    expect($json['warnings'][0]['code'])->toBe('mention_not_member');
});

test('an agent mention reads as the owner\'s agent', function () {
    [, $token] = makeAgent($this->alex, AgentLevel::Write, name: 'helper');
    asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => '@sam@example.com ping'])->assertCreated();

    $row = mentionRows($this->sam)->sole()->load(['issue.project', 'agent.owner', 'actor']);
    expect(InboxItemResource::make($row)->resolve()['text'])->toBe("Alex's agent mentioned you on WEB-1");
});

test('an agent mentioning its own owner notifies nobody', function () {
    [, $token] = makeAgent($this->alex, AgentLevel::Write);
    asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => '@alex@example.com me'])->assertCreated();

    expect(InboxItem::where('event', 'mention')->count())->toBe(0);
});

test('the 21st agent mention in an hour warns and notifies nobody', function () {
    [, $token] = makeAgent($this->alex, AgentLevel::Write);
    for ($i = 1; $i <= 20; $i++) {
        $r = asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => "ping {$i} @sam@example.com"])->assertCreated();
        expect($r->json('data.warnings'))->toBeNull();
    }
    $r = asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => 'one more @sam@example.com'])->assertCreated();

    expect($r->json('data.warnings.0.code'))->toBe('mention_rate_limited')->and(mentionRows($this->sam))->toHaveCount(20);

    $this->travel(61)->minutes();
    $r = asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => 'later @sam@example.com'])->assertCreated();
    expect($r->json('data.warnings'))->toBeNull()->and(mentionRows($this->sam))->toHaveCount(21);
});

test('an issue stays in Waiting on you until the mentioned person comments', function () {
    app(CommentService::class)->add($this->issue, $this->ann, 'Sam, thoughts? @sam@example.com');
    $focus = fn () => collect($this->actingAs($this->sam)->getJson('/api/v1/me/issues')->assertOk()->json('data'))->pluck('focus', 'key')->all();

    expect($focus()['WEB-1'])->toBe('waiting_on_you');

    $this->travel(5)->seconds();
    app(CommentService::class)->add($this->issue, $this->sam, 'Done thinking');
    expect($focus()['WEB-1'])->not->toBe('waiting_on_you');
    expect(app(MentionService::class)->unanswered($this->sam, collect([$this->issue->id]))->all())->toBe([]);
});

test('mentions in descriptions work the same, on create and update', function () {
    $issue = app(IssueService::class)->create($this->project, $this->ann, ['title' => 'New', 'description' => 'For @sam@example.com']);
    expect(mentionRows($this->sam))->toHaveCount(1)->and(Mention::first()->comment_id)->toBeNull();

    app(IssueService::class)->update($issue, $this->ann, ['description' => 'For @sam@example.com and @alex@example.com']);
    expect(mentionRows($this->sam))->toHaveCount(1)->and(mentionRows($this->alex))->toHaveCount(1);

    apiAs($this->ann, AgentLevel::Write);
    $res = $this->patchJson('/api/v1/issues/'.$issue->key, ['version' => $issue->fresh()->version, 'description' => 'x @nobody@example.com'])->assertOk();
    expect($res->json('data.warnings.0.code'))->toBe('mention_not_member');
});

test('mentions in code and links make nothing', function () {
    app(CommentService::class)->add($this->issue, $this->ann, "`@sam@example.com`\n\n```\n@sam@example.com\n```\n\n[@sam@example.com](https://x.test)");

    expect(Mention::count())->toBe(0);
});

test('the mentionable list needs sight of the project', function () {
    $outsider = User::factory()->create();
    joinWorkspace($outsider);
    asWorkspaceMember($outsider);

    $this->actingAs($outsider)->getJson(w('/projects/WEB/mentionable'))->assertForbidden();
    $this->actingAs($this->sam)->getJson(w('/projects/WEB/mentionable'))->assertOk()
        ->assertJsonPath('members.0.email', 'alex@example.com')->assertJsonCount(3, 'members');
});
