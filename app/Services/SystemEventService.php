<?php

namespace App\Services;

use App\Jobs\DeliverSystemEvent;
use App\Models\SystemEvent;
use App\Models\Workspace;

/** 3-R22: writes an event to the outbox and queues its delivery. Off unless the URL and the secret are both set. */
class SystemEventService
{
    public const NO_USAGE = ['billed_users' => 0, 'viewers' => 0, 'pending_billed_invitations' => 0, 'projects' => 0, 'storage_mb' => 0];

    public function __construct(private WorkspaceUsage $usage) {}

    public static function enabled(): bool
    {
        return (bool) config('pm.system_events_url') && (bool) config('pm.system_events_secret');
    }

    /**
     * @param  array<string, mixed>  $data  the type's own fields; a deleted workspace passes null and its id as workspace_id
     */
    public function record(string $type, ?Workspace $workspace, array $data): ?SystemEvent
    {
        if (! self::enabled()) {
            return null;
        }
        $event = new SystemEvent(['type' => $type, 'workspace_id' => $workspace?->id ?? $data['workspace_id'] ?? null]);
        $event->id = $event->newUniqueId();
        $event->payload = [
            'id' => $event->id, 'type' => $type, 'occurred_at' => now()->toIso8601String(), 'workspace_id' => $event->workspace_id,
            // A deleted workspace has nothing left to count.
            'usage' => $workspace ? $this->usage->snapshot($workspace) : self::NO_USAGE,
            ...$data,
        ];
        $event->save();
        DeliverSystemEvent::dispatch($event->id);

        return $event;
    }
}
