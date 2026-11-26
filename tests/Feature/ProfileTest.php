<?php

use App\Models\User;
use App\Services\WorkspaceMemberService;
use Illuminate\Validation\ValidationException;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});

test('a deletion refused under the lock keeps the person signed in (PR 50 review)', function () {
    $user = User::factory()->create();
    $this->partialMock(WorkspaceMemberService::class, fn ($mock) => $mock->shouldReceive('deleteAccount')
        ->andThrow(ValidationException::withMessages(['password' => 'You are the last Owner of Acme.'])));

    $this->actingAs($user)->from('/profile')->delete('/profile', ['password' => 'password'])
        ->assertSessionHasErrors('password')->assertRedirect('/profile');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->not->toBeNull();
});

test('signing out after the delete does not save the deleted user back (PR 50 review)', function () {
    $user = User::factory()->create(['remember_token' => 'remember-me-token']);

    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertSessionHasNoErrors()->assertRedirect('/');

    $this->assertGuest();
    expect(User::whereKey($user->id)->exists())->toBeFalse();
});
