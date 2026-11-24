<?php

use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

// Owner review 2: a Close as Duplicate whose after-commit link failed is repaired daily from the activity log.

beforeEach(function () {
    $this->admin = User::factory()->create();
    $web = makeProject($this->admin, 'WEB');
    foreach (range(1, 4) as $n) {
        makeIssue($web, $this->admin, ['title' => "Issue {$n}"]);
    }
    apiAsPerson($this->admin);
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 1, 'status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-3'])->assertOk();
    $this->patchJson('/api/v1/issues/WEB-2', ['version' => 1, 'status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-4'])->assertOk();
    // Both links lost, as if the after-commit insert had failed; WEB-2's original is then purged.
    IssueLink::query()->delete();
    DB::table('issues')->where('number', 4)->delete();
});

test('links:reconcile-duplicates repairs a missing link, counts the ones it can\'t, and a second run changes nothing', function () {
    $this->artisan('links:reconcile-duplicates')
        ->expectsOutput('Duplicate links: 1 repaired, 1 skipped (original not recorded, purged, missing, in another workspace or removed on purpose).')
        ->assertSuccessful();
    $link = IssueLink::sole();
    expect($link->type->value)->toBe('duplicates')->and($link->created_by)->toBe($this->admin->id)->and($link->via)->toBe('web');

    $this->artisan('links:reconcile-duplicates')
        ->expectsOutput('Duplicate links: 0 repaired, 1 skipped (original not recorded, purged, missing, in another workspace or removed on purpose).')
        ->assertSuccessful();
    expect(IssueLink::count())->toBe(1);
});

test('a duplicates link a person removed on purpose stays removed', function () {
    $this->artisan('links:reconcile-duplicates')->assertSuccessful();
    $this->deleteJson('/api/v1/issues/WEB-1/links', ['type' => 'duplicates', 'to' => 'WEB-3'])->assertNoContent();

    $this->artisan('links:reconcile-duplicates')
        ->expectsOutput('Duplicate links: 0 repaired, 2 skipped (original not recorded, purged, missing, in another workspace or removed on purpose).')
        ->assertSuccessful();
    expect(IssueLink::count())->toBe(0);

    // Closed as a duplicate again afterwards: the new record wins, and a lost link is repaired.
    $this->patchJson('/api/v1/issues/WEB-1', ['version' => 2, 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-3'])->assertOk();
    IssueLink::query()->delete();
    $this->artisan('links:reconcile-duplicates')->assertSuccessful();
    expect(IssueLink::sole()->source_issue_id)->toBe(Issue::where('number', 1)->value('id'));
});

test('an Undo that couldn\'t restore the replaced original isn\'t undone by the repair', function () {
    $this->artisan('links:reconcile-duplicates')->assertSuccessful();
    $this->actingAs($this->admin);
    $this->post(w('/issues/WEB-1/links'), ['type' => 'duplicates', 'to' => 'WEB-2'])->assertSessionHasNoErrors();
    $undo = session('undo');
    app(IssueService::class)->delete(Issue::where('number', 3)->with('project')->sole(), $this->admin);
    $this->delete($undo['url'], $undo['data'])->assertSessionHasErrors('undo');
    expect(IssueLink::count())->toBe(0);

    $this->artisan('links:reconcile-duplicates')->assertSuccessful();
    expect(IssueLink::count())->toBe(0);
});

test('it runs daily', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'links:reconcile-duplicates'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 0 * * *');
});
