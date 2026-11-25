<?php

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Services\WorkspaceMemberService;

// 3-R14: who may subscribe to the live channels. Members of the project (or Owners and Admins, who see every
// project read-only) of that workspace, people only; user.{u} is only u's.

beforeEach(function () {
    useReverbForChannelAuth();
    $this->ann = User::factory()->create();
    $this->project = makeProject($this->ann, 'WEB');
    $this->workspace = $this->project->workspace;
    $this->channel = "workspace.{$this->workspace->id}.project.{$this->project->id}";
});

test('a project member is authorised', function () {
    $bob = addMember($this->project, User::factory()->create(), ProjectRole::Viewer);

    channelAuth($bob, $this->channel)->assertOk()->assertJsonStructure(['auth']);
    channelAuth($this->ann, $this->channel)->assertOk();
});

test('a member of another workspace is refused', function () {
    $eve = User::factory()->create();
    makeWorkspace($eve, 'other');

    channelAuth($eve, $this->channel)->assertForbidden();
});

test('a project the channel names in another workspace is refused, even to a member of both', function () {
    $other = makeWorkspace($this->ann, 'other');
    makeProject($this->ann, 'OPS');

    // ann's own WEB project, but named under the other workspace's id.
    channelAuth($this->ann, "workspace.{$other->id}.project.{$this->project->id}")->assertForbidden();
});

test('a removed member is refused', function () {
    $bob = addMember($this->project, User::factory()->create(), ProjectRole::Member);
    app(WorkspaceMemberService::class)->remove($this->workspace, $this->ann, $bob);

    channelAuth($bob, $this->channel)->assertForbidden();
});

test('a workspace Member not in the project is refused; an Admin before joining is allowed (read-only view)', function () {
    $mia = User::factory()->create();
    $this->workspace->addMember($mia, WorkspaceRole::Member);
    $adam = User::factory()->create();
    $this->workspace->addMember($adam, WorkspaceRole::Admin);

    channelAuth($mia, $this->channel)->assertForbidden();
    channelAuth($adam, $this->channel)->assertOk();
});

test('an agent token is refused', function () {
    [, $token] = makeAgent($this->ann);

    asToken($token)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-{$this->channel}"])->assertForbidden();
    asToken($token)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-user.{$this->ann->id}"])->assertForbidden();
});

test('only the user may have user.{u}; a guessed id of someone else is refused', function () {
    $bob = addMember($this->project, User::factory()->create(), ProjectRole::Member);

    channelAuth($bob, "user.{$bob->id}")->assertOk();
    channelAuth($bob, "user.{$this->ann->id}")->assertForbidden();
});

test('a malformed channel name is refused, not an error', function () {
    channelAuth($this->ann, 'workspace.x.project.y')->assertForbidden();
});
