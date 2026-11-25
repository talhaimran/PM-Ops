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
use Illuminate\Support\Facades\RateLimiter;

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

// Fix round 1.

test('a mentioned watcher gets the mention row only, another watcher still gets commented', function () {
    $bob = addMember($this->project, User::factory()->create(['email' => 'bob@example.com']), ProjectRole::Member);
    app(WatcherService::class)->watch($this->issue, $bob, $bob);
    InboxItem::query()->delete();

    app(CommentService::class)->add($this->issue, $this->ann, 'Sam please @sam@example.com');

    expect(InboxItem::where('user_id', $this->sam->id)->pluck('event')->all())->toBe(['mention'])
        ->and(InboxItem::where('user_id', $bob->id)->pluck('event')->all())->toBe(['commented']);
});

test('cloning and Undo make no mentions', function () {
    $issue = app(IssueService::class)->create($this->project, $this->ann, ['title' => 'M', 'description' => 'For @sam@example.com']);
    $before = [Mention::count(), InboxItem::where('event', 'mention')->count()];

    app(IssueService::class)->clone($issue, $this->ann);
    expect([Mention::count(), InboxItem::where('event', 'mention')->count()])->toBe($before);

    app(IssueService::class)->update($issue->refresh(), $this->ann, ['description' => 'plain']);
    Mention::query()->delete();
    InboxItem::query()->delete();
    app(IssueService::class)->update($issue->refresh(), $this->ann, ['description' => 'For @sam@example.com'], 'undid');
    expect(Mention::count())->toBe(0)->and(InboxItem::where('event', 'mention')->count())->toBe(0);
});

test('editing a mention out removes it and the issue leaves Waiting on you', function () {
    $comment = app(CommentService::class)->add($this->issue, $this->ann, 'Hi @sam@example.com');
    $unanswered = fn () => app(MentionService::class)->unanswered($this->sam, collect([$this->issue->id]))->all();
    expect($unanswered())->toBe([$this->issue->id]);

    InboxItem::query()->delete();
    app(CommentService::class)->update($comment, 'Hi there', $comment->version);

    expect($unanswered())->toBe([])->and(Mention::count())->toBe(0)->and(InboxItem::count())->toBe(0);

    $issue = app(IssueService::class)->create($this->project, $this->ann, ['title' => 'D', 'description' => '@sam@example.com']);
    app(IssueService::class)->update($issue, $this->ann, ['description' => null]);
    expect(Mention::where('issue_id', $issue->id)->count())->toBe(0);
});

test('a deleted comment\'s mention no longer counts', function () {
    $comment = app(CommentService::class)->add($this->issue, $this->ann, 'Hi @sam@example.com');
    app(CommentService::class)->delete($comment);

    expect(app(MentionService::class)->unanswered($this->sam, collect([$this->issue->id]))->all())->toBe([]);
});

test('web: an Admin gets the invite prompt, a Member the cannot-see warning, and an edit warns the editor', function () {
    $this->actingAs($this->ann)->post(w('/issues/WEB-1/comments'), ['body' => 'cc @x@example.com'])
        ->assertSessionHas('mention_invite', ['project' => 'WEB', 'emails' => ['x@example.com']]);

    $this->actingAs($this->alex)->post(w('/issues/WEB-1/comments'), ['body' => 'cc @x@example.com'])
        ->assertSessionHas('warning', "x@example.com can't see this issue.")->assertSessionMissing('mention_invite');

    $comment = app(CommentService::class)->add($this->issue, $this->alex, 'plain');
    $this->actingAs($this->alex)->patch(w("/comments/{$comment->id}"), ['body' => 'now @y@example.com', 'version' => $comment->version])
        ->assertSessionHas('warning', "y@example.com can't see this issue.");
});

// Owner review.

test('editing mentions out gives an agent no capacity back, and the hour resets it', function () {
    config(['pm.agent_mention_limit' => 2]);
    RateLimiter::clear('pm:agent-mentions:'.makeAgent($this->ann)[0]->id);
    [$agent, $token] = makeAgent($this->ann, AgentLevel::Write);
    RateLimiter::clear("pm:agent-mentions:{$agent->id}");

    $c = asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => '@sam@example.com @alex@example.com'])->assertCreated();
    asToken($token)->patchJson('/api/v1/comments/'.$c->json('data.id'), ['body' => 'nothing', 'version' => 1])->assertOk();
    expect(Mention::count())->toBe(0);

    InboxItem::query()->delete();
    $r = asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => 'again @sam@example.com'])->assertCreated();
    expect($r->json('data.warnings.0.code'))->toBe('mention_rate_limited')->and(Mention::count())->toBe(0)->and(mentionRows($this->sam))->toHaveCount(0);

    $this->travel(61)->minutes();
    $r = asToken($token)->postJson('/api/v1/issues/WEB-1/comments', ['body' => 'later @sam@example.com'])->assertCreated();
    expect($r->json('data.warnings'))->toBeNull()->and(Mention::count())->toBe(1);
});

test('two comments by one author in the same second: the mention row belongs to the first only', function () {
    $this->freezeTime();
    InboxItem::query()->delete();
    app(CommentService::class)->add($this->issue, $this->ann, 'first @sam@example.com');
    app(CommentService::class)->add($this->issue, $this->ann, 'second, no mention');

    expect(InboxItem::where('user_id', $this->sam->id)->orderBy('id')->pluck('event')->all())->toBe(['mention', 'commented']);
});
