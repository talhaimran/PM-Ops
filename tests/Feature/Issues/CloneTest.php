<?php

use App\Enums\AgentLevel;
use App\Enums\LinkType;
use App\Enums\ProjectRole;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\CloneIssue;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\Label;
use App\Models\User;
use App\Services\LinkService;
use App\Services\SprintService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Spec 7.9 (2-R7): cloning copies title, description, type, labels and story points into a new issue in the same
// project, linked "clone clones original". Sub-tasks and links are options; comments and history never come along.

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->web = makeProject($this->admin, 'WEB');
    Label::create(['project_id' => $this->web->id, 'name' => 'frontend', 'color' => '#3b82f6']);
    app(SprintService::class)->create($this->web, ['name' => 'S1']);
    $this->original = makeIssue($this->web, $this->admin, [
        'title' => 'Original', 'description' => 'Body', 'type' => 'bug', 'labels' => ['frontend'], 'story_points' => 5,
        'status' => 'In Progress', 'assignee' => $this->admin->email, 'sprint' => 'S1', 'due_date' => '2026-12-01', 'start_date' => '2026-11-30',
    ]);
    $this->issue = fn (int $n) => Issue::where('number', $n)->with('project', 'status')->sole();
    apiAsPerson($this->admin);
});

test('a clone copies the content, starts in To Do and links to the original', function () {
    $this->postJson('/api/v1/issues/WEB-1/clone')->assertCreated()
        ->assertJsonPath('data.key', 'WEB-2')->assertJsonPath('data.title', 'Original')->assertJsonPath('data.description', 'Body')
        ->assertJsonPath('data.type', 'bug')->assertJsonPath('data.labels', ['frontend'])->assertJsonPath('data.story_points', 5)
        ->assertJsonPath('data.status', 'To Do')->assertJsonPath('data.assignee', null);

    $clone = ($this->issue)(2);
    expect($clone->sprint_id)->toBeNull()->and($clone->epic_id)->toBeNull()->and($clone->start_date)->toBeNull()->and($clone->due_date)->toBeNull()
        ->and(IssueLink::where('source_issue_id', $clone->id)->where('target_issue_id', $this->original->id)->sole()->type)->toBe(LinkType::Clones);
});

test('the created activity says it was cloned, and both issues log the link', function () {
    $this->postJson('/api/v1/issues/WEB-1/clone')->assertCreated();

    $created = ($this->issue)(2)->activities()->where('action', 'created')->sole();
    expect($created->changes['cloned_from'])->toEqual(['from' => null, 'to' => 'WEB-1'])->and($created->via)->toBe('web');
    expect(($this->issue)(2)->activities()->where('action', 'linked')->count())->toBe(1)
        ->and($this->original->activities()->where('action', 'linked')->count())->toBe(1);
});

test('comments and history are not copied', function () {
    $this->postJson('/api/v1/issues/WEB-1/comments', ['body' => 'hello'])->assertCreated();
    $this->postJson('/api/v1/issues/WEB-1/clone')->assertCreated();

    $clone = ($this->issue)(2);
    expect($clone->comments()->count())->toBe(0)->and($clone->activities()->where('action', 'commented')->count())->toBe(0);
});

test('subtasks: true clones each sub-task under the clone, by the same rules; false leaves them', function () {
    makeIssue($this->web, $this->admin, ['title' => 'Sub A', 'parent' => 'WEB-1', 'status' => 'In Progress', 'labels' => ['frontend'], 'story_points' => 2, 'assignee' => $this->admin->email]);
    makeIssue($this->web, $this->admin, ['title' => 'Sub B', 'parent' => 'WEB-1']);

    $this->postJson('/api/v1/issues/WEB-1/clone')->assertCreated();
    expect(Issue::where('parent_id', ($this->issue)(4)->id)->count())->toBe(0);

    $this->postJson('/api/v1/issues/WEB-1/clone', ['subtasks' => true])->assertCreated();
    $subs = Issue::where('parent_id', ($this->issue)(5)->id)->with('status', 'labels')->orderBy('number')->get();
    expect($subs->pluck('title')->all())->toBe(['Sub A', 'Sub B'])
        ->and($subs[0]->status->name)->toBe('To Do')->and($subs[0]->assignee_id)->toBeNull()
        ->and($subs[0]->story_points)->toBe(2)->and($subs[0]->labels->pluck('name')->all())->toBe(['frontend'])
        ->and(IssueLink::where('source_issue_id', $subs[0]->id)->where('type', LinkType::Clones)->count())->toBe(1);
});

test('links: true copies blocks, precedes and relates to in both directions, never duplicates or clones', function () {
    foreach (range(2, 8) as $n) {
        makeIssue($this->web, $this->admin, ['title' => "Other {$n}"]);
    }
    $links = app(LinkService::class);
    $o = fn (int $n) => ($this->issue)($n);
    $links->link($o(1), 'blocks', $o(2), $this->admin);
    $links->link($o(3), 'blocks', $o(1), $this->admin);
    $links->link($o(1), 'precedes', $o(4), $this->admin);
    $links->link($o(5), 'precedes', $o(1), $this->admin);
    $links->link($o(1), 'relates to', $o(6), $this->admin);
    $links->link($o(1), 'duplicates', $o(7), $this->admin);
    $links->link($o(8), 'clones', $o(1), $this->admin);

    $this->postJson('/api/v1/issues/WEB-1/clone')->assertCreated()->assertJsonPath('data.key', 'WEB-9');
    expect(IssueLink::where('source_issue_id', $o(9)->id)->orWhere('target_issue_id', $o(9)->id)->count())->toBe(1);

    $this->postJson('/api/v1/issues/WEB-1/clone', ['links' => true])->assertCreated()->assertJsonPath('data.key', 'WEB-10');
    $clone = $o(10);
    $mine = IssueLink::where('source_issue_id', $clone->id)->orWhere('target_issue_id', $clone->id)->with('source.project', 'target.project')->get()
        ->map(fn ($l) => "{$l->source->key} {$l->type->value} {$l->target->key}")->sort()->values()->all();
    // The re-entrant link lock was released once per acquire: nothing still holds it.
    expect(DB::scalar('SELECT IS_USED_LOCK(?)', ["pm:links:{$this->web->workspace_id}"]))->toBeNull();
    expect($mine)->toBe(['WEB-10 blocks WEB-2', 'WEB-10 clones WEB-1', 'WEB-10 precedes WEB-4', 'WEB-3 blocks WEB-10', 'WEB-5 precedes WEB-10', 'WEB-6 relates to WEB-10']);
});

test('links: true skips links to issues the actor cannot see, silently', function () {
    $secret = makeProject($this->admin, 'SEC');
    $hidden = makeIssue($secret, $this->admin, ['title' => 'Hidden']);
    makeIssue($this->web, $this->admin, ['title' => 'Seen']);
    $links = app(LinkService::class);
    $links->link($this->original->load('project'), 'blocks', ($this->issue)(2), $this->admin);
    $links->link($this->original, 'blocks', $hidden->load('project'), $this->admin);

    $sam = User::factory()->create();
    addMember($this->web, $sam, ProjectRole::Member);
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($sam);
    $this->post(w('/issues/WEB-1/clone'), ['links' => true])->assertRedirect();

    $clone = ($this->issue)(3);
    $others = IssueLink::where('source_issue_id', $clone->id)->where('type', LinkType::Blocks)->with('target.project')->get()->map(fn ($l) => $l->target->key);
    expect($others->all())->toBe(['WEB-2']);
});

test('a Viewer and a read-only agent get 403; a Write agent can clone', function () {
    $viewer = User::factory()->create();
    addMember($this->web, $viewer, ProjectRole::Viewer);
    $this->flushHeaders();
    app('auth')->forgetGuards();
    apiAsPerson($viewer);
    $this->postJson('/api/v1/issues/WEB-1/clone')->assertForbidden();

    $this->flushHeaders();
    app('auth')->forgetGuards();
    apiAs($this->admin, AgentLevel::Read);
    $this->postJson('/api/v1/issues/WEB-1/clone')->assertForbidden();
    PmServer::tool(CloneIssue::class, ['key' => 'WEB-1'])->assertHasErrors();

    $this->flushHeaders();
    app('auth')->forgetGuards();
    apiAs($this->admin, AgentLevel::Write);
    $this->postJson('/api/v1/issues/WEB-1/clone')->assertCreated();
    expect(Issue::count())->toBe(2);
});

test('MCP clone_issue clones, with options', function () {
    makeIssue($this->web, $this->admin, ['title' => 'Sub', 'parent' => 'WEB-1']);
    $this->flushHeaders();
    app('auth')->forgetGuards();
    apiAs($this->admin, AgentLevel::Write);

    PmServer::tool(CloneIssue::class, ['key' => 'WEB-1', 'subtasks' => true])->assertOk()->assertSee('WEB-3');
    expect(Issue::where('parent_id', ($this->issue)(3)->id)->count())->toBe(1)
        ->and(($this->issue)(3)->activities()->where('action', 'created')->sole()->via)->toBe('mcp');
});

test('web clone redirects to the new issue and flashes an Undo that deletes it', function () {
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($this->admin);

    $this->post(w('/issues/WEB-1/clone'))->assertRedirect(w('/issues/WEB-2'))
        ->assertSessionHas('undo.url', url(w('/issues/WEB-2')));
});

test('a failure part way leaves nothing behind', function () {
    // Force a failure after the clone exists: an epic sub-task can't be cloned under a clone.
    makeIssue($this->web, $this->admin, ['title' => 'Sub', 'parent' => 'WEB-1']);
    Issue::where('title', 'Sub')->update(['type' => 'epic']); // an epic can't be a sub-task: its clone is refused
    $before = Issue::count();

    $this->postJson('/api/v1/issues/WEB-1/clone', ['subtasks' => true])->assertUnprocessable();
    expect(Issue::count())->toBe($before)->and(IssueLink::count())->toBe(0);
});

test('a clone over the cap is refused before anything is saved or locked', function () {
    config(['pm.clone_limit' => 2]);
    foreach (range(1, 3) as $n) {
        makeIssue($this->web, $this->admin, ['title' => "Sub {$n}", 'parent' => 'WEB-1']);
    }
    foreach (range(1, 3) as $n) {
        app(LinkService::class)->link($this->original, 'relates to', makeIssue($this->web, $this->admin, ['title' => "Rel {$n}"]), $this->admin);
    }
    $issues = Issue::count();

    $this->postJson('/api/v1/issues/WEB-1/clone', ['subtasks' => true])->assertUnprocessable()
        ->assertJsonPath('errors.subtasks.0', 'WEB-1 has 3 sub-tasks; a clone copies at most 2. Clone it without sub-tasks.');
    $this->postJson('/api/v1/issues/WEB-1/clone', ['links' => true])->assertUnprocessable()
        ->assertJsonPath('errors.links.0', 'WEB-1 has 3 links to copy; a clone copies at most 2. Clone it without links.');
    expect(Issue::count())->toBe($issues)
        ->and(DB::scalar('SELECT IS_USED_LOCK(?)', ['pm:links:'.$this->web->workspace_id]))->toBeNull();

    // Within the cap on each option, it clones.
    $this->postJson('/api/v1/issues/WEB-1/clone')->assertCreated();
});

test('sub-tasks or links added after the early count still can\'t push a clone past the cap', function (string $option) {
    config(['pm.clone_limit' => 2]);
    $add = fn (int $n) => $option === 'subtasks'
        ? makeIssue($this->web, $this->admin, ['title' => "Sub {$n}", 'parent' => 'WEB-1'])
        : app(LinkService::class)->link($this->original, 'relates to', makeIssue($this->web, $this->admin, ['title' => "Rel {$n}"]), $this->admin);
    $add(1);
    $issues = null;
    // The race: two more arrive once the clone's transaction has begun, after the early count passed.
    $fired = false;
    Event::listen(TransactionBeginning::class, function () use (&$fired, &$issues, $add) {
        if ($fired) {
            return;
        }
        $fired = true;
        $add(2);
        $add(3);
        $issues = Issue::count();
    });

    $this->postJson('/api/v1/issues/WEB-1/clone', [$option => true])->assertUnprocessable()
        ->assertJsonPath("errors.{$option}.0", fn ($m) => str_contains($m, 'a clone copies at most 2'));
    expect(Issue::where('title', 'Original')->count())->toBe(1);
})->with(['subtasks', 'links']);
