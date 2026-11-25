<?php

use App\Enums\ProjectRole;
use App\Enums\Reaction;
use App\Events\NotificationsChanged;
use App\Events\ProjectChanged;
use App\Models\User;
use App\Services\BoardService;
use App\Services\CommentService;
use App\Services\IssueService;
use App\Services\NotificationService;
use App\Services\ReactionService;
use App\Services\WatcherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// 3-R14 / 3-R15: what goes out on the live channels, after commit, with ids and keys only.

beforeEach(function () {
    $this->ann = User::factory()->create(['name' => 'Ann Secret-Name', 'email' => 'ann-secret@example.com']);
    $this->project = makeProject($this->ann, 'WEB');
    $this->bob = addMember($this->project, User::factory()->create(), ProjectRole::Member);
    $this->issue = makeIssue($this->project, $this->ann, ['title' => 'Plain']);
    $this->channel = "private-workspace.{$this->project->workspace_id}.project.{$this->project->id}";
    Event::fake([ProjectChanged::class, NotificationsChanged::class]);
});

/** @return list<ProjectChanged> */
function projectChanges(): array
{
    return Event::dispatched(ProjectChanged::class)->map(fn ($args) => $args[0])->values()->all();
}

test('an issue update broadcasts one ProjectChanged on the project channel, with no title or text', function () {
    app(IssueService::class)->update($this->issue, $this->ann, ['title' => 'Secret title', 'description' => 'Secret text']);

    $changes = projectChanges();
    expect($changes)->toHaveCount(1)
        ->and($changes[0]->broadcastOn()->name)->toBe($this->channel)
        ->and($changes[0]->issueKey)->toBe('WEB-1')
        ->and($changes[0]->kind)->toBe('updated')
        ->and(array_keys(get_object_vars($changes[0])))->toEqual(['workspaceId', 'projectId', 'issueKey', 'kind'])
        ->and(json_encode($changes[0]))->not->toContain('Secret');
});

test('a change that rolls back broadcasts nothing', function () {
    try {
        DB::transaction(function () {
            app(IssueService::class)->update($this->issue, $this->ann, ['title' => 'Renamed']);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(ProjectChanged::class);
});

test('a comment, its edit and delete, and a reaction broadcast', function () {
    $comments = app(CommentService::class);
    $comment = $comments->add($this->issue, $this->ann, 'Secret comment');
    $comments->update($comment, 'Secret edit', $comment->version);
    app(ReactionService::class)->add($comment->fresh(), Reaction::ThumbsUp, $this->bob);
    $comments->delete($comment);

    expect(collect(projectChanges())->pluck('kind')->all())->toBe(['commented', 'comment_edited', 'reaction', 'comment_deleted'])
        ->and(collect(projectChanges())->every(fn ($e) => $e->issueKey === 'WEB-1' && $e->broadcastOn()->name === $this->channel))->toBeTrue()
        ->and(json_encode(projectChanges()))->not->toContain('Secret');
});

test('reordering a card within its column broadcasts', function () {
    makeIssue($this->project, $this->ann);
    Event::fake([ProjectChanged::class]);

    app(BoardService::class)->move($this->issue, $this->ann, ['after' => 'WEB-2']);

    expect(collect(projectChanges())->pluck('kind')->all())->toBe(['ranked']);
});

test('a notification broadcasts NotificationsChanged to its user only', function () {
    app(WatcherService::class)->watch($this->issue, $this->bob, $this->bob);
    Event::fake([ProjectChanged::class, NotificationsChanged::class]);

    app(CommentService::class)->add($this->issue, $this->ann, 'Hello');

    $sent = Event::dispatched(NotificationsChanged::class)->map(fn ($args) => $args[0]);
    expect($sent)->toHaveCount(1)
        ->and($sent->first()->broadcastOn()->name)->toBe("private-user.{$this->bob->id}")
        ->and(array_keys(get_object_vars($sent->first())))->toEqual(['userId']);
});

test('marking read refreshes the bell; marking nothing does not', function () {
    app(WatcherService::class)->watch($this->issue, $this->bob, $this->bob);
    app(CommentService::class)->add($this->issue, $this->ann, 'Hello');
    Event::fake([NotificationsChanged::class]);
    $notifications = app(NotificationService::class);

    $notifications->markRead($this->bob, 'all');
    $notifications->markRead($this->bob, 'all');

    Event::assertDispatchedTimes(NotificationsChanged::class, 1);
});

test('a live reload of an issue page whose issue was deleted goes to the board instead of back to itself', function () {
    $page = w('/issues/WEB-1');
    app(IssueService::class)->delete($this->issue, $this->ann);

    $this->actingAs($this->ann)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Referer' => url($page)])->get($page)
        ->assertRedirect(url(w('/projects/WEB')))->assertSessionHas('warning');
    // Opened from elsewhere, it still goes back there.
    $this->withHeaders(['Referer' => url(w('/dashboard'))])->get($page)->assertRedirect(url(w('/dashboard')));
});
