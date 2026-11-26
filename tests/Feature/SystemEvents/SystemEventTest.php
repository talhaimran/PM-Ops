<?php

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Jobs\DeliverSystemEvent;
use App\Models\AuditLog;
use App\Models\SystemEvent;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\MembershipService;
use App\Services\ProjectService;
use App\Services\SystemEventService;
use App\Services\WorkspaceMemberService;
use App\Services\WorkspaceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use JsonSchema\Validator;

// 3-R21 / 3-R22: audit rows that matter to Platform management become signed outbox events, after the change commits.

const EVENT_SCHEMAS = [
    'workspace.created' => 'workspace.json', 'workspace.updated' => 'workspace.json', 'workspace.deleted' => 'workspace.json', 'workspace.activity' => 'workspace.json',
    'workspace.owner_changed' => 'owner-changed.json',
    'member.added' => 'member.json', 'member.removed' => 'member.json', 'member.role_changed' => 'member.json',
    'project.created' => 'project.json', 'project.deleted' => 'project.json',
];

function enableSystemEvents(): void
{
    config(['pm.system_events_url' => 'https://platform.example.test/events', 'pm.system_events_secret' => 'events-secret']);
}

/** @return list<string> */
function eventErrors(SystemEvent $event): array
{
    $path = base_path('docs/contract/events/'.EVENT_SCHEMAS[$event->type]);
    $validator = new Validator;
    $value = json_decode(json_encode($event->payload));
    $validator->validate($value, (object) ['$ref' => 'file://'.$path]);

    return array_map(fn ($e) => "{$e['property']}: {$e['message']}", $validator->getErrors());
}

function events(string $type)
{
    return SystemEvent::where('type', $type)->get();
}

beforeEach(function () {
    Queue::fake();
    enableSystemEvents();
    $this->owner = User::factory()->create(['name' => 'Olive Owner']);
    $this->ws = makeWorkspace($this->owner, 'acme');
});

test('every 3-R21 source records one valid event with the usage snapshot and its fields', function () {
    $this->actingAs($this->owner);
    $project = makeProject($this->owner, 'WEB');
    app(WorkspaceService::class)->rename($this->ws, 'Acme Co', 'acme');
    $inv = app(InvitationService::class)->invite($this->ws, $this->owner, 'new@example.com', WorkspaceRole::Member, $project, ProjectRole::Member);
    $new = User::factory()->create(['email' => 'new@example.com', 'name' => 'Nia New']);
    app(InvitationService::class)->accept($inv, $new);
    $members = app(WorkspaceMemberService::class);
    $members->changeRole($this->ws, $this->owner, $new, WorkspaceRole::Owner);
    $members->changeRole($this->ws, $this->owner, $new, WorkspaceRole::Member);
    $members->changeRole($this->ws, $this->owner, $new, WorkspaceRole::Admin);
    $members->remove($this->ws, $this->owner, $new);
    app(ProjectService::class)->delete($project);
    app(WorkspaceService::class)->purge($this->ws);
    $this->artisan('system-events:activity', ['day' => now()->toDateString()])->assertSuccessful();

    $expected = ['workspace.created' => 1, 'project.created' => 1, 'workspace.updated' => 1, 'member.added' => 1,
        'workspace.owner_changed' => 2, 'member.role_changed' => 1, 'member.removed' => 1, 'project.deleted' => 1, 'workspace.deleted' => 1];
    foreach ($expected as $type => $count) {
        expect(events($type))->toHaveCount($count, $type);
    }
    // The workspace was purged before the daily command ran, so it has no activity event.
    expect(events('workspace.activity'))->toHaveCount(0);

    foreach (SystemEvent::all() as $event) {
        expect(eventErrors($event))->toBe([], $event->type)
            ->and($event->payload['id'])->toBe($event->id)
            ->and($event->payload['workspace_id'])->toBe($this->ws->id);
    }
    $created = events('workspace.created')->sole()->payload;
    expect($created['name'])->toBe('Acme')->and($created['slug'])->toBe('acme')
        ->and($created['owner'])->toEqual(['id' => $this->owner->id, 'email' => $this->owner->email, 'name' => 'Olive Owner'])
        ->and($created['usage'])->toEqual(['billed_users' => 1, 'viewers' => 0, 'pending_billed_invitations' => 0, 'projects' => 0, 'storage_mb' => 0]);
    expect(events('workspace.updated')->sole()->payload['changes'])->toEqual(['before' => ['name' => 'Acme'], 'after' => ['name' => 'Acme Co']])
        ->and(events('workspace.owner_changed')->pluck('payload.change')->sort()->values()->all())->toBe(['added', 'removed'])
        ->and(events('member.added')->sole()->payload)->toMatchArray(['role' => 'member', 'billed' => true, 'user' => ['id' => $new->id, 'email' => 'new@example.com', 'name' => 'Nia New']])
        ->and(events('member.removed')->sole()->payload)->toMatchArray(['role' => 'admin', 'billed' => true])
        ->and(events('project.deleted')->sole()->payload['project'])->toMatchArray(['key' => 'WEB', 'name' => 'Website'])
        ->and(events('workspace.deleted')->sole()->payload)->toMatchArray(['slug' => 'acme']);
    Queue::assertPushed(DeliverSystemEvent::class, SystemEvent::count());
});

test('a workspace role change sends member.role_changed with billed before and after', function () {
    $this->actingAs($this->owner);
    $member = User::factory()->create();
    $this->ws->addMember($member, WorkspaceRole::Member);

    app(WorkspaceMemberService::class)->changeRole($this->ws, $this->owner, $member, WorkspaceRole::Admin);

    expect(events('member.role_changed')->sole()->payload)->toMatchArray([
        'scope' => 'workspace', 'project' => null, 'roles' => ['before' => 'member', 'after' => 'admin'], 'billed_change' => ['before' => false, 'after' => true],
    ]);
});

test('a Viewer to Member project role change sends member.role_changed; one that keeps the billed status sends nothing', function () {
    $this->actingAs($this->owner);
    $project = makeProject($this->owner, 'WEB');
    $viewer = addMember($project, User::factory()->create(), ProjectRole::Viewer);

    app(MembershipService::class)->changeRole($project, $viewer, ProjectRole::Member);
    app(MembershipService::class)->changeRole($project, $viewer, ProjectRole::Admin);

    expect(events('member.role_changed'))->toHaveCount(1)
        ->and(events('member.role_changed')->sole()->payload)->toMatchArray([
            'scope' => 'project', 'project' => 'WEB', 'roles' => ['before' => 'viewer', 'after' => 'member'], 'billed_change' => ['before' => false, 'after' => true],
        ])
        ->and(eventErrors(events('member.role_changed')->sole()))->toBe([]);
});

test('a rolled-back change records nothing', function () {
    $this->actingAs($this->owner);
    $before = SystemEvent::count();

    try {
        DB::transaction(function () {
            makeProject($this->owner, 'WEB');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(SystemEvent::count())->toBe($before)->and(events('project.created'))->toHaveCount(0);
});

test('nothing is recorded unless both the URL and the secret are set', function (array $config) {
    config($config);
    Queue::fake();
    SystemEvent::query()->delete();
    $this->actingAs($this->owner);

    makeProject($this->owner, 'WEB');

    expect(SystemEvent::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'no url' => [['pm.system_events_url' => null]],
    'no secret' => [['pm.system_events_secret' => null]],
]);

test('workspace.activity goes out at most once per workspace per day, only for workspaces with activity that day', function () {
    $quiet = makeWorkspace(User::factory()->create(), 'quiet');
    useWorkspace($this->ws, $this->owner);
    $this->actingAs($this->owner);
    $project = makeProject($this->owner, 'WEB');
    makeIssue($project, $this->owner);
    SystemEvent::query()->delete();
    $quiet->update(['created_at' => now()->subYear()]);
    DB::table('audit_logs')->where('workspace_id', $quiet->id)->delete();

    $this->artisan('system-events:activity', ['day' => now()->toDateString()])->assertSuccessful();
    $this->artisan('system-events:activity', ['day' => now()->toDateString()])->assertSuccessful();
    $this->artisan('system-events:activity', ['day' => now()->subDay()->toDateString()])->assertSuccessful();

    $events = events('workspace.activity');
    expect($events)->toHaveCount(1)
        ->and($events->sole()->workspace_id)->toBe($this->ws->id)
        ->and($events->sole()->payload['day'])->toBe(now()->toDateString())
        ->and(eventErrors($events->sole()))->toBe([]);
});

test('the schemas carry examples that validate', function (string $file) {
    $schema = json_decode(file_get_contents($file), flags: JSON_THROW_ON_ERROR);
    expect($schema->examples ?? [])->not->toBeEmpty();
    foreach ($schema->examples as $example) {
        $validator = new Validator;
        $value = $example;
        $validator->validate($value, (object) ['$ref' => 'file://'.$file]);
        expect($validator->getErrors())->toBe([], basename($file));
    }
})->with(fn () => glob(__DIR__.'/../../../docs/contract/events/*.json'));

test('adding a workspace Viewer to a project as Member sends one member.role_changed; removing them from their last billed project sends another', function () {
    $this->actingAs($this->owner);
    $project = makeProject($this->owner, 'WEB');
    $viewer = User::factory()->create();
    $this->ws->addMember($viewer, WorkspaceRole::Member);
    SystemEvent::query()->delete();

    app(MembershipService::class)->add($project, $viewer, ProjectRole::Member);
    expect(events('member.role_changed'))->toHaveCount(1)
        ->and(events('member.role_changed')->sole()->payload)->toMatchArray([
            'scope' => 'project', 'project' => 'WEB', 'roles' => ['before' => null, 'after' => 'member'], 'billed_change' => ['before' => false, 'after' => true],
        ])
        ->and(eventErrors(events('member.role_changed')->sole()))->toBe([]);

    SystemEvent::query()->delete();
    app(MembershipService::class)->remove($project, $viewer);
    expect(events('member.role_changed'))->toHaveCount(1)
        ->and(events('member.role_changed')->sole()->payload)->toMatchArray([
            'scope' => 'project', 'project' => 'WEB', 'roles' => ['before' => 'member', 'after' => null], 'billed_change' => ['before' => true, 'after' => false],
        ])
        ->and(eventErrors(events('member.role_changed')->sole()))->toBe([]);
});

test('a project add or remove that keeps the billed status sends nothing', function () {
    $this->actingAs($this->owner);
    $project = makeProject($this->owner, 'WEB');
    $admin = User::factory()->create();
    $this->ws->addMember($admin, WorkspaceRole::Admin);
    SystemEvent::query()->delete();

    app(MembershipService::class)->add($project, $admin, ProjectRole::Member);
    app(MembershipService::class)->remove($project, $admin);

    expect(SystemEvent::count())->toBe(0)
        ->and(AuditLog::whereIn('action', ['project.member_added', 'project.member_removed'])->count())->toBe(0);
});

test('an existing member accepting a project invitation sends no member.added (PR 49 review)', function () {
    $this->actingAs($this->owner);
    $project = makeProject($this->owner, 'WEB');
    $member = User::factory()->create(['email' => 'mia@example.com']);
    $this->ws->addMember($member, WorkspaceRole::Member);
    SystemEvent::query()->delete();

    $inv = app(InvitationService::class)->invite($this->ws, $this->owner, 'mia@example.com', WorkspaceRole::Member, $project, ProjectRole::Viewer);
    app(InvitationService::class)->accept($inv, $member);

    expect(AuditLog::where('action', 'member.joined')->where('target_id', $member->id)->exists())->toBeTrue()
        ->and(events('member.added'))->toHaveCount(0);
});

test('an event whose delivery job was never queued is queued again after 10 minutes, at most once per 10 minutes (PR 49 review)', function () {
    $this->freezeSecond();
    $stranded = SystemEvent::where('type', 'workspace.created')->sole();
    $fresh = app(SystemEventService::class)->record('workspace.updated', $this->ws, ['changes' => ['before' => [], 'after' => []]]);
    $delivered = app(SystemEventService::class)->record('workspace.updated', $this->ws, ['changes' => ['before' => [], 'after' => []]]);
    $tried = app(SystemEventService::class)->record('workspace.updated', $this->ws, ['changes' => ['before' => [], 'after' => []]]);
    SystemEvent::whereKey([$stranded->id, $delivered->id, $tried->id])->update(['created_at' => now()->subMinutes(11), 'updated_at' => now()->subMinutes(11)]);
    $delivered->forceFill(['delivered_at' => now()])->saveQuietly();
    SystemEvent::whereKey($delivered->id)->update(['updated_at' => now()->subMinutes(11)]);
    SystemEvent::whereKey($tried->id)->update(['attempts' => 1]);
    Queue::fake();

    expect(app(SystemEventService::class)->dispatchPending())->toBe(1);
    Queue::assertPushed(DeliverSystemEvent::class, 1);
    Queue::assertPushed(DeliverSystemEvent::class, fn ($job) => $job->eventId === $stranded->id);

    expect(app(SystemEventService::class)->dispatchPending())->toBe(0);
    $this->travel(11)->minutes();
    expect(app(SystemEventService::class)->dispatchPending())->toBe(2); // $stranded again, and $fresh now
});

test('a second workspace.activity run for the same day waits its turn instead of racing (PR 49 review)', function () {
    useWorkspace($this->ws, $this->owner);
    $this->actingAs($this->owner);
    makeIssue(makeProject($this->owner, 'WEB'), $this->owner);
    $lock = Cache::lock('pm:system-events:activity:'.now()->toDateString(), 60);
    $lock->get();

    $this->artisan('system-events:activity', ['day' => now()->toDateString()])->expectsOutputToContain('already running')->assertFailed();
    expect(events('workspace.activity'))->toHaveCount(0);

    $lock->release();
    $this->artisan('system-events:activity', ['day' => now()->toDateString()])->assertSuccessful();
    expect(events('workspace.activity'))->toHaveCount(1);
});
