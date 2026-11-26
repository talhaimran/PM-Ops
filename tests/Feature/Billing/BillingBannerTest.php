<?php

use App\Enums\Entitlement;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Services\Entitlements;
use Inertia\Testing\AssertableInertia as Assert;

// 3-R24: informational billing keys shown by the core; enforcement stays with Platform management.

function setBilling($t, Entitlement $key, string $value): void
{
    app(Entitlements::class)->set($t->ws, $key, $value, 'plan');
}

/** The shared `billing` prop as $user sees the usage page. */
function billingProp($t, User $user): array
{
    $t->actingAs($user)->get('/acme/settings/usage')->assertOk()->assertInertia(fn (Assert $page) => $t->prop = $page->toArray()['props']['billing']);

    return $t->prop;
}

beforeEach(function () {
    $this->freezeSecond();
    config(['pm.billing_url' => 'https://billing.example.test', 'pm.billing_link_secret' => 'billing-secret']);
    $this->owner = User::factory()->create();
    $this->ws = makeWorkspace($this->owner, 'acme');
    $this->member = User::factory()->create();
    $this->ws->addMember($this->member, WorkspaceRole::Member);
    $this->link = '/acme/settings/billing';
});

test('nothing is shown without billing keys', function () {
    expect(billingProp($this, $this->owner))->toBe(['link' => $this->link, 'trial_days' => null, 'notice' => null])
        ->and(billingProp($this, $this->member))->toBe(['link' => null, 'trial_days' => null, 'notice' => null]);
});

test('Owners see the days left in a trial; Members and an ended trial see nothing', function () {
    setBilling($this, Entitlement::TrialEndsAt, now()->addDays(9)->toDateString());

    expect(billingProp($this, $this->owner)['trial_days'])->toBe(9)
        ->and(billingProp($this, $this->member)['trial_days'])->toBeNull();

    setBilling($this, Entitlement::TrialEndsAt, now()->toDateString());
    expect(billingProp($this, $this->owner)['trial_days'])->toBe(0);

    setBilling($this, Entitlement::TrialEndsAt, now()->subDay()->toDateString());
    expect(billingProp($this, $this->owner)['trial_days'])->toBeNull();
});

test('past_due and canceling are for Owners, with the Billing link', function (string $notice) {
    setBilling($this, Entitlement::BillingNotice, $notice);

    expect(billingProp($this, $this->owner))->toMatchArray(['notice' => $notice, 'link' => $this->link])
        ->and(billingProp($this, $this->member))->toMatchArray(['notice' => null, 'link' => null]);
})->with(['past_due', 'canceling']);

test('read_only shows to everyone, with the link for Owners only', function () {
    setBilling($this, Entitlement::BillingNotice, 'read_only');

    expect(billingProp($this, $this->owner))->toMatchArray(['notice' => 'read_only', 'link' => $this->link])
        ->and(billingProp($this, $this->member))->toMatchArray(['notice' => 'read_only', 'link' => null]);
});

test('an unknown notice shows nothing, and no Billing env hides the link but not the notice', function () {
    setBilling($this, Entitlement::BillingNotice, 'read_only');
    config(['pm.billing_link_secret' => null]);

    expect(billingProp($this, $this->owner))->toMatchArray(['notice' => 'read_only', 'link' => null]);

    setBilling($this, Entitlement::BillingNotice, 'whatever');
    expect(billingProp($this, $this->owner)['notice'])->toBeNull();
});

test('the plan name shows on Usage and limits', function () {
    setBilling($this, Entitlement::Plan, 'trial');

    $this->actingAs($this->member)->get('/acme/settings/usage')->assertInertia(fn (Assert $page) => $page->where('plan', 'Trial'));
});

test('no plan key, no plan name', function () {
    $this->actingAs($this->member)->get('/acme/settings/usage')->assertInertia(fn (Assert $page) => $page->where('plan', null));
});
