<?php

use App\Models\User;

// Owner review of step 2 PR 5: a profile update returns only to a page on this site, never to an external Referer.
test('a profile update never redirects to an external Referer', function (string $referer) {
    $this->actingAs(User::factory()->create())
        ->patch('/profile', ['my_issues_group' => 'priority'], ['Referer' => $referer])
        ->assertRedirect(route('profile.edit'));
})->with(['https://evil.example/phish', 'http://localhost.evil.example/x', '//evil.example/x']);

test('a grouping change returns to the dashboard it came from, filters kept', function () {
    $user = User::factory()->create();
    $back = url('/demo/dashboard?category=done');

    $this->actingAs($user)->patch('/profile', ['my_issues_group' => 'status'], ['Referer' => $back])->assertRedirect($back);
    expect($user->fresh()->my_issues_group)->toBe('status');
});
