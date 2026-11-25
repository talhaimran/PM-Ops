<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** 3-R14: the user's inbox changed (a notification, or items marked read); their bell reloads its count. The user id only. */
class NotificationsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(public int $userId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    /** Without a broadcaster nothing is queued (see ProjectChanged). */
    public function broadcastWhen(): bool
    {
        return ProjectChanged::broadcasting();
    }
}
