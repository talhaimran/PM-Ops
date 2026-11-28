<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** B-R13: one of the starter's runs moved on (queued, running, step, finished, failed, cancelled, changes: applied or discarded); their run panel reloads it. Ids and kind only. */
class AgentRunChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(public int $userId, public int $runId, public string $kind) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'agent_run';
    }

    public function broadcastWith(): array
    {
        return ['run_id' => $this->runId, 'kind' => $this->kind];
    }

    /** Without a broadcaster nothing is queued (see ProjectChanged). */
    public function broadcastWhen(): bool
    {
        return ProjectChanged::broadcasting();
    }
}
