<?php

use App\Enums\AgentLevel;
use App\Enums\IssueType;
use App\Enums\Resolution;
use App\Enums\SprintState;
use App\Enums\WorkspaceRole;
use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\CommentReaction;
use App\Models\InboxItem;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\IssueWatcher;
use App\Models\Mention;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

test('demo seed builds a usable project and prints a working token', function () {
    $this->artisan('db:seed')
        ->expectsOutputToContain('demo@example.com')
        ->expectsOutputToContain('Demo API token')
        ->expectsOutputToContain('PM_MCP_TOKEN')
        ->expectsOutputToContain('/demo/settings/agents')
        ->assertSuccessful();

    $workspace = Workspace::sole();
    $role = fn (string $email) => User::firstWhere('email', $email)->workspaceRole($workspace);
    expect($workspace->only('name', 'slug'))->toBe(['name' => 'Demo', 'slug' => 'demo'])
        ->and($role('demo@example.com'))->toBe(WorkspaceRole::Owner)
        ->and($role('alex@example.com'))->toBe(WorkspaceRole::Admin)
        ->and($role('sam@example.com'))->toBe(WorkspaceRole::Member);

    $demo = User::where('email', 'demo@example.com')->sole();
    $project = Project::where('key', 'WEB')->sole();
    $active = $project->sprints()->where('state', SprintState::Active)->sole();

    expect($project->roleFor($demo)?->value)->toBe('admin')
        ->and($project->members()->count())->toBe(3)
        ->and($project->roleFor(User::firstWhere('email', 'sam@example.com'))?->value)->toBe('viewer')
        ->and($project->workspace_id)->toBe($workspace->id)
        ->and(Issue::count())->toBeGreaterThanOrEqual(28)->toBeLessThanOrEqual(35)
        ->and(Issue::where('type', IssueType::Epic)->count())->toBe(3)
        ->and(Issue::whereNotNull('parent_id')->count())->toBeGreaterThan(0)
        ->and($active->issues()->count())->toBeGreaterThanOrEqual(8)
        ->and($project->sprints()->where('state', SprintState::Planned)->count())->toBe(1)
        ->and(Issue::where('assignee_id', $demo->id)->count())->toBeGreaterThan(0)
        ->and(Agent::where('name', 'Demo token')->sole()->permission_level)->toBe(AgentLevel::Write)
        ->and(PersonalAccessToken::where('name', 'Demo token')->sole()->agent_id)->not->toBeNull();
});

test('demo seed has a Won\'t do issue and a status with a custom colour', function () {
    $this->artisan('db:seed')->assertSuccessful();

    $issue = Issue::where('title', 'Animated hero video')->sole();
    expect($issue->resolution)->toBe(Resolution::WontDo)->and($issue->resolved_at)->not->toBeNull()
        ->and(Status::where('name', 'In Review')->sole()->color)->toBe('cobalt');
});

test('demo seed leaves one pending approval that demo can decide', function () {
    $this->artisan('db:seed')->assertSuccessful();

    $approval = ApprovalRequest::open()->sole();
    $demo = User::where('email', 'demo@example.com')->sole();

    expect($approval->agent->name)->toBe('Demo token')
        ->and($approval->issue->key)->toBe('WEB-5')
        ->and($approval->action['to_status'])->toBe('Done')
        ->and($demo->can('decide', $approval))->toBeTrue();
});

test('demo seed has a blocks link, a dated precedes chain and a clone', function () {
    $this->artisan('db:seed')->assertSuccessful();
    $key = fn (IssueLink $l) => $l->source->key.' '.$l->type->value.' '.$l->target->key;
    $links = IssueLink::with(['source.project', 'target.project'])->get()->map($key)->all();

    expect($links)->toContain('WEB-7 blocks WEB-9', 'WEB-14 precedes WEB-15', 'WEB-15 precedes WEB-17')
        ->and($links)->toHaveCount(4)->and($links)->toContain('WEB-31 clones WEB-12')
        ->and(Issue::whereIn('number', [14, 15, 17])->whereNull('start_date')->exists())->toBeFalse();
});

test('the printed demo token works against the api', function () {
    Artisan::call('db:seed');
    preg_match('/Demo API token: (\S+)/', Artisan::output(), $m);

    $this->withToken($m[1])->getJson('/api/v1/projects/WEB/summary')
        ->assertOk()
        ->assertJsonPath('data.active_sprint.name', 'WEB Sprint 1');
});

test('seeding twice skips instead of failing', function () {
    $this->artisan('db:seed')->assertSuccessful();
    $this->artisan('db:seed')->expectsOutputToContain('already seeded')->assertSuccessful();

    expect(User::where('email', 'demo@example.com')->count())->toBe(1);
});

test('demo seed has watchers, a mention of demo, a reaction and an unread inbox', function () {
    $this->artisan('db:seed')->assertSuccessful();
    $demo = User::where('email', 'demo@example.com')->sole();
    $sam = User::where('email', 'sam@example.com')->sole();
    $web10 = Issue::where('number', 10)->sole();

    expect(IssueWatcher::where('user_id', $demo->id)->count())->toBeGreaterThan(2)
        ->and(IssueWatcher::where('user_id', $sam->id)->exists())->toBeTrue()
        ->and(Mention::where('mentioned_user_id', $demo->id)->where('by_user_id', User::firstWhere('email', 'alex@example.com')->id)->exists())->toBeTrue()
        ->and(CommentReaction::count())->toBeGreaterThan(0)
        ->and(InboxItem::where('user_id', $demo->id)->whereNull('read_at')->count())->toBeGreaterThan(1)
        ->and(InboxItem::where('user_id', $demo->id)->where('issue_id', $web10->id)->exists())->toBeTrue();
});
