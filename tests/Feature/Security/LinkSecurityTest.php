<?php

use App\Enums\AgentLevel;
use App\Enums\ProjectRole;
use App\Mcp\Servers\PmServer;
use App\Mcp\Tools\GetIssue;
use App\Mcp\Tools\GetIssueActivity;
use App\Mcp\Tools\LinkIssues;
use App\Mcp\Tools\SearchIssues;
use App\Mcp\Tools\UnlinkIssues;
use App\Models\ApprovalRequest;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LinkService;

// Review Focus 3 and 2-D2: links never reveal an issue the caller can't see; 2-R3 and 2-D3: who may link.

beforeEach(function () {
    $this->admin = User::factory()->create(['email' => 'admin@example.com']);
    $this->web = makeProject($this->admin, 'WEB');
    $this->ops = makeProject($this->admin, 'OPS');
    makeIssue($this->web, $this->admin, ['title' => 'Web one']);
    makeIssue($this->web, $this->admin, ['title' => 'Web two', 'status' => 'In Progress']);
    makeIssue($this->ops, $this->admin, ['title' => 'Hidden ops work']);
    // member sees WEB only: a plain workspace Member, not in OPS.
    $this->member = addMember($this->web, User::factory()->create(), ProjectRole::Member);
    $this->issue = fn (string $project, int $n) => Issue::whereHas('project', fn ($p) => $p->where('key', $project))->where('number', $n)->with('project')->sole();
});

test('linking to an issue in an unseen project or another workspace is the usual 404', function () {
    apiAs($this->member);
    $missing = $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'NOPE-1'])->assertNotFound()->json();
    $unseen = $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'OPS-1'])->assertNotFound()->json();
    expect(str_replace('OPS', 'NOPE', json_encode($unseen)))->toBe(json_encode($missing));

    // Another workspace, where the member also has a project with an issue.
    $other = makeWorkspace($this->member, 'other');
    makeIssue(makeProject($this->member, 'OTH'), $this->member, ['title' => 'Other workspace']);
    useWorkspace(Workspace::find($this->web->workspace_id), $this->member);
    apiAs($this->member);
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'OTH-1'])->assertNotFound()->assertJsonPath('errors.project.0', fn ($m) => str_starts_with($m, 'Project "OTH" was not found.'));
    PmServer::tool(LinkIssues::class, ['from' => 'WEB-1', 'type' => 'blocks', 'to' => 'OPS-1'])->assertHasErrors(['Project "OPS" was not found.']);

    expect(IssueLink::count())->toBe(0);
});

test('a Viewer and a read-only agent get 403; an agent at Read level can\'t link or unlink (2-D3)', function () {
    $viewer = addMember($this->web, User::factory()->create(), ProjectRole::Viewer);
    app(LinkService::class)->link(($this->issue)('WEB', 1), 'relates to', ($this->issue)('WEB', 2), $this->admin);

    $this->actingAs($viewer)->post(w('/issues/WEB-1/links'), ['type' => 'blocks', 'to' => 'WEB-2'])->assertForbidden();
    apiAsPerson($viewer);
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'WEB-2'])->assertForbidden();
    $this->deleteJson('/api/v1/issues/WEB-1/links', ['type' => 'relates to', 'to' => 'WEB-2'])->assertForbidden();

    apiAs($this->member, AgentLevel::Read);
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'WEB-2'])->assertForbidden()
        ->assertJsonPath('message', 'This agent is read-only. Its owner can change its level in Settings → Agents.');
    PmServer::tool(LinkIssues::class, ['from' => 'WEB-1', 'type' => 'blocks', 'to' => 'WEB-2'])->assertHasErrors(['read-only']);
    PmServer::tool(UnlinkIssues::class, ['from' => 'WEB-1', 'type' => 'relates to', 'to' => 'WEB-2'])->assertHasErrors(['read-only']);

    expect(IssueLink::count())->toBe(1);
});

test('unlinking needs contribute on either issue\'s project (2-R3)', function () {
    $opsOnly = addMember($this->ops, User::factory()->create(), ProjectRole::Member);
    addMember($this->web, $opsOnly, ProjectRole::Viewer);
    app(LinkService::class)->link(($this->issue)('WEB', 1), 'blocks', ($this->issue)('OPS', 1), $this->admin);

    // A viewer of WEB can't link from WEB, but contributes in OPS, so may remove the link.
    apiAsPerson($opsOnly);
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'relates to', 'to' => 'OPS-1'])->assertForbidden();
    $this->deleteJson('/api/v1/issues/WEB-1/links', ['type' => 'blocks', 'to' => 'OPS-1'])->assertNoContent();

    expect(IssueLink::count())->toBe(0);
});

test('an issue\'s links, Blocked flag, filters, warnings and activity never show an unseen issue', function () {
    $links = app(LinkService::class);
    $links->link(($this->issue)('OPS', 1), 'blocks', ($this->issue)('WEB', 1), $this->admin);
    $links->link(($this->issue)('WEB', 2), 'relates to', ($this->issue)('OPS', 1), $this->admin);
    $links->link(($this->issue)('WEB', 2), 'blocks', ($this->issue)('WEB', 1), $this->admin);

    apiAsPerson($this->member);
    $rest = $this->getJson('/api/v1/issues/WEB-1')->assertOk()->assertJsonPath('data.blocked', true);
    expect(array_keys($rest->json('data.links')))->toBe(['is blocked by'])->and($rest->json('data.links.is blocked by'))->toHaveCount(1);
    foreach (['/api/v1/issues/WEB-1', '/api/v1/issues/WEB-2', '/api/v1/issues/WEB-1/activity', '/api/v1/issues/WEB-2/activity', '/api/v1/projects/WEB/issues'] as $url) {
        expect($this->getJson($url)->assertOk()->getContent())->not->toContain('OPS-1')->not->toContain('Hidden ops work');
    }
    // Blocked only by the unseen issue once WEB-2 is resolved: no badge, no filter match, no warning.
    apiAsPerson($this->admin);
    $this->postJson('/api/v1/issues/WEB-2/move', ['status' => 'Done'])->assertOk();
    apiAsPerson($this->member);
    $this->getJson('/api/v1/issues/WEB-1')->assertJsonPath('data.blocked', false);
    expect($this->getJson('/api/v1/issues?blocked=1')->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/issues?blocked_by=OPS-1')->json('data'))->toBe([]);
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'In Progress'])->assertOk();
    // The admin, who sees OPS, gets the badge.
    apiAsPerson($this->admin);
    $this->getJson('/api/v1/issues/WEB-1')->assertJsonPath('data.blocked', true);

    apiAs($this->member);
    foreach ([[GetIssue::class, ['issue' => 'WEB-1']], [GetIssue::class, ['issue' => 'WEB-2']], [GetIssueActivity::class, ['issue' => 'WEB-1']], [SearchIssues::class, ['blocked_by' => 'OPS-1']]] as [$tool, $args]) {
        PmServer::tool($tool, $args)->assertOk()->assertDontSee('OPS-1')->assertDontSee('Hidden ops work');
    }

    foreach (['/issues/WEB-1', '/issues/WEB-2', '/projects/WEB/issues', '/projects/WEB', '/dashboard', '/projects/WEB/issues?issue=WEB-2'] as $path) {
        $props = json_encode($this->actingAs($this->member)->get(w($path))->assertOk()->viewData('page')['props']);
        expect($props)->not->toContain('OPS-1')->not->toContain('Hidden ops work');
    }
});

test('a Duplicate\'s original stays hidden from people who can\'t see it: activity, 409s and Undo (2-D2)', function () {
    apiAsPerson($this->admin);
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'OPS-1'])->assertOk();
    // The admin, who sees OPS, still reads it.
    expect($this->getJson('/api/v1/issues/WEB-1/activity')->getContent())->toContain('OPS-1');

    apiAsPerson($this->member);
    $activity = $this->getJson('/api/v1/issues/WEB-1/activity')->assertOk();
    expect($activity->getContent())->not->toContain('OPS-1')->and($activity->json('data.0.changes'))->toHaveKeys(['status', 'resolution']);
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'title' => 'Stale'])->assertConflict()->assertDontSee('OPS-1');
    apiAs($this->member);
    PmServer::tool(GetIssueActivity::class, ['issue' => 'WEB-1'])->assertOk()->assertDontSee('OPS-1');

    $this->actingAs($this->member);
    expect(json_encode($this->get(w('/issues/WEB-1'))->assertOk()->viewData('page')['props']))->not->toContain('OPS-1');
    $this->patch(w('/issues/WEB-1'), ['version' => 1, 'title' => 'Stale']);
    expect(json_encode(session('conflict')))->not->toContain('OPS-1');
    $this->flushSession();
    $this->patch(w('/issues/WEB-1'), ['version' => 2, 'status' => 'In Progress'])->assertSessionHasNoErrors();
    expect(json_encode(session('undo')))->not->toContain('OPS-1');
    $this->post(w('/issues/WEB-1/move'), ['status' => 'Done'])->assertSessionHasNoErrors();
    expect(json_encode(session('undo')))->not->toContain('OPS-1');
});

test('an agent\'s Duplicate request never shows its original to a decider who can\'t see it; approving still works (2-D2)', function () {
    $webAdmin = addMember($this->web, User::factory()->create(), ProjectRole::Admin);
    apiAs($this->admin);
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'OPS-1'])->assertStatus(202)
        ->assertJsonPath('approval_request.duplicate_of', 'OPS-1');
    $id = ApprovalRequest::sole()->id;

    apiAsPerson($webAdmin);
    $rest = $this->getJson('/api/v1/issues/WEB-1')->assertOk()->assertJsonPath('data.pending_approvals.0.resolution', 'duplicate');
    expect($rest->getContent())->not->toContain('OPS-1')->and($rest->json('data.pending_approvals.0.duplicate_of'))->toBeNull();
    apiAs($webAdmin);
    PmServer::tool(GetIssue::class, ['issue' => 'WEB-1'])->assertOk()->assertSee('"pending_approvals":[{')->assertDontSee('OPS-1');
    // The web as the person, not their agent's token.
    app('auth')->forgetGuards();
    $webAdmin = $webAdmin->fresh();
    foreach (['/issues/WEB-1', '/dashboard', '/projects/WEB?issue=WEB-1'] as $path) {
        $props = $this->actingAs($webAdmin)->get(w($path))->assertOk()->viewData('page')['props'];
        expect(json_encode($props))->toContain("\"id\":{$id}")->not->toContain('OPS-1');
    }

    $this->actingAs($webAdmin)->post(w("/approvals/{$id}/approve"))->assertRedirect()->assertSessionHasNoErrors();
    expect(json_encode(session()->all()))->not->toContain('OPS-1')
        ->and(Issue::where('number', 1)->whereHas('project', fn ($p) => $p->where('key', 'WEB'))->sole())
        ->resolution->value->toBe('duplicate')->status->name->toBe('Done')
        ->and(IssueLink::sole()->target_issue_id)->toBe(($this->issue)('OPS', 1)->id);
    // The agent's owner, who sees OPS, still reads it.
    apiAsPerson($this->admin);
    expect($this->getJson('/api/v1/issues/WEB-1/activity')->getContent())->toContain('OPS-1');
});

test('approving a Duplicate request whose original is gone marks it stale without naming it', function () {
    apiAs($this->admin);
    $this->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'OPS-1'])->assertStatus(202);
    ($this->issue)('OPS', 1)->forceDelete();
    app('auth')->forgetGuards();

    $this->actingAs($this->admin->fresh())->post(w('/approvals/'.ApprovalRequest::sole()->id.'/approve'))->assertRedirect();

    expect(ApprovalRequest::sole())->status->value->toBe('stale')->note->toBe('The issue WEB-1 would duplicate is no longer available.');
});

test('scan F1: "is duplicated by" can\'t repoint the original of an issue the caller can only view', function () {
    // The member can view OPS (Viewer) but not write there; OPS-1 is already a duplicate of OPS-2.
    addMember($this->ops, $this->member, ProjectRole::Viewer);
    makeIssue($this->ops, $this->admin, ['title' => 'Ops original']);
    app(LinkService::class)->link(($this->issue)('OPS', 1), 'duplicates', ($this->issue)('OPS', 2), $this->admin);

    apiAs($this->member);
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'is duplicated by', 'to' => 'OPS-1'])->assertForbidden();
    $this->postJson('/api/v1/issues/WEB-1/links', ['type' => 'is duplicated by', 'to' => 'WEB-2'])->assertCreated();

    expect(IssueLink::where('type', 'duplicates')->where('source_issue_id', ($this->issue)('OPS', 1)->id)->sole()->target_issue_id)
        ->toBe(($this->issue)('OPS', 2)->id);
});
