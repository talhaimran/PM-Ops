<?php

use App\Models\IssueLink;
use App\Models\User;
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
        ->expectsOutput('Duplicate links: 1 repaired, 1 skipped (original not recorded, purged, missing or in another workspace).')
        ->assertSuccessful();
    $link = IssueLink::sole();
    expect($link->type->value)->toBe('duplicates')->and($link->created_by)->toBe($this->admin->id)->and($link->via)->toBe('web');

    $this->artisan('links:reconcile-duplicates')
        ->expectsOutput('Duplicate links: 0 repaired, 1 skipped (original not recorded, purged, missing or in another workspace).')
        ->assertSuccessful();
    expect(IssueLink::count())->toBe(1);
});

test('it runs daily', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'links:reconcile-duplicates'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 0 * * *');
});
