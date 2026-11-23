<?php

use App\Enums\AgentLevel;
use App\Enums\ApprovalStatus;
use App\Models\Activity;
use App\Models\ApprovalRequest;
use App\Models\Issue;
use App\Models\User;

// Review Focus 2: the resolution rides on the approval request, and never moves an issue by itself.

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->web = makeProject($this->owner, 'WEB');
    makeIssue($this->web, $this->owner, ['title' => 'one', 'status' => 'In Review']);
    makeIssue($this->web, $this->owner, ['title' => 'two']);
    [$this->agent, $this->plain] = makeAgent($this->owner, AgentLevel::Write);
});

test('an agent sending a resolution with a Done status waits for a person; approving applies both together', function () {
    $response = asToken($this->plain)->patchJson('/api/v1/issues/WEB-1', ['status' => 'Done', 'resolution' => 'wont_do', 'reason' => 'Out of scope'])
        ->assertStatus(202)->assertJsonPath('status', 'pending_approval')->assertJsonPath('issue.resolution', null);
    $id = $response->json('approval_request.id');
    $issue = fn () => Issue::where('number', 1)->sole()->load('status');

    expect($issue()->status->name)->toBe('In Review')->and($issue()->resolution)->toBeNull()
        ->and(ApprovalRequest::sole()->action)->toMatchArray(['to_status' => 'Done', 'resolution' => 'wont_do']);

    $this->actingAs($this->owner)->post(w("/approvals/{$id}/approve"))->assertSessionHasNoErrors();

    expect($issue()->status->name)->toBe('Done')->and($issue()->resolution->value)->toBe('wont_do')->and($issue()->resolved_at)->not->toBeNull()
        ->and(ApprovalRequest::sole()->status)->toBe(ApprovalStatus::Approved);
});

test('a duplicate request carries duplicate_of and is refused without it', function () {
    asToken($this->plain)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done', 'resolution' => 'duplicate'])->assertUnprocessable()->assertJsonValidationErrors('duplicate_of');
    expect(ApprovalRequest::count())->toBe(0);

    $id = asToken($this->plain)->postJson('/api/v1/issues/WEB-1/move', ['status' => 'Done', 'resolution' => 'duplicate', 'duplicate_of' => 'WEB-2'])
        ->assertStatus(202)->json('approval_request.id');
    $this->actingAs($this->owner)->post(w("/approvals/{$id}/approve"))->assertSessionHasNoErrors();

    expect(Issue::where('number', 1)->sole()->resolution->value)->toBe('duplicate')
        ->and(Activity::where('action', 'approved')->sole()->changes)->toMatchArray(['duplicate_of' => ['from' => null, 'to' => 'WEB-2']]);
});

test('a resolution alone on an open issue is a 422, never a move', function () {
    asToken($this->plain)->patchJson('/api/v1/issues/WEB-1', ['resolution' => 'wont_do'])->assertUnprocessable()
        ->assertJsonPath('errors.resolution.0', 'Resolution applies to Done statuses. Move the issue to one of: Done.');
    asToken($this->plain)->postJson('/api/v1/issues/WEB-1/move', ['resolution' => 'wont_do'])->assertUnprocessable();

    expect(Issue::where('number', 1)->sole()->status->name)->toBe('In Review')->and(ApprovalRequest::count())->toBe(0);
});

test('1-D7: an agent changing the resolution of an already-Done issue needs no approval', function () {
    $this->actingAs($this->owner)->post(w('/issues/WEB-2/move'), ['status' => 'Done'])->assertSessionHasNoErrors();

    asToken($this->plain)->patchJson('/api/v1/issues/WEB-2', ['resolution' => 'cannot_reproduce'])->assertOk()->assertJsonPath('data.resolution', 'cannot_reproduce');

    $activity = Activity::latest('id')->first();
    expect(ApprovalRequest::count())->toBe(0)
        ->and(Issue::where('number', 2)->sole()->resolution->value)->toBe('cannot_reproduce')
        ->and($activity->changes)->toEqual(['resolution' => ['from' => 'done', 'to' => 'cannot_reproduce']])
        ->and($activity->agent_id)->toBe($this->agent->id);
});
