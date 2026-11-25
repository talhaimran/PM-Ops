<?php

use App\Enums\ProjectRole;
use App\Enums\Reaction;
use App\Events\NotificationsChanged;
use App\Events\ProjectChanged;
use App\Models\User;
use App\Services\BoardService;
use App\Services\CommentService;
use App\Services\IssueService;
use App\Services\MembershipService;
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
    // phpunit.xml sets the null broadcaster, with which nothing is sent (see the last test).
    config(['broadcasting.default' => 'reverb']);
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
        ->and(json_encode($changes[0]))->not->toContain('Secret')
        // Scan F1/F2: the wire payload is empty, so a socket kept open after losing access learns only that the project changed.
        ->and($changes[0]->broadcastWith())->toBe([]);
});

test('a change that rolls back broadcasts nothing', function () {
    $comment = app(CommentService::class)->add($this->issue, $this->ann, 'Hello');
    Event::fake([ProjectChanged::class]);
    try {
        DB::transaction(function () use ($comment) {
            app(IssueService::class)->update($this->issue, $this->ann, ['title' => 'Renamed']);
            // Dispatched by the service itself (not through ActivityRecorded), so only ProjectChanged's own after-commit holds it.
            app(CommentService::class)->update($comment, 'Edited', $comment->version);
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

test('with the null broadcaster an issue change neither looks the issue up again nor dispatches ProjectChanged', function (?string $default) {
    config(['broadcasting.default' => $default]);
    DB::enableQueryLog();

    app(IssueService::class)->update($this->issue, $this->ann, ['title' => 'Renamed']);

    Event::assertNotDispatched(ProjectChanged::class);
    // ProjectChanged::about's lookup: by id, deleted issues included (no deleted_at condition).
    $issueLookups = collect(DB::getQueryLog())->filter(fn ($q) => str_ends_with($q['query'], 'from `issues` where `issues`.`id` = ? limit 1'));
    expect($issueLookups)->toHaveCount(0);
    expect((new NotificationsChanged($this->ann->id))->broadcastWhen())->toBeFalse();
})->with([
    'the string null' => ['null'],
    // BROADCAST_CONNECTION=null in .env: env() turns it into a real null.
    'null from the env' => [null],
]);

test('that redirect goes to the project list when the reader can no longer see the project', function () {
    $page = w('/issues/WEB-1');
    asWorkspaceMember($this->bob);
    app(MembershipService::class)->remove($this->project, $this->bob);

    $this->actingAs($this->bob)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Referer' => url($page)])->get($page)
        ->assertRedirect(url(w('/projects')))->assertSessionHas('warning');
});
