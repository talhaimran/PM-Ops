<?php

namespace App\Listeners;

use App\Events\AuditRecorded;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SystemEventService;
use App\Services\WorkspaceUsage;

/**
 * 3-R21/3-R22: the audit rows Platform management cares about become system events, after the change commits.
 * shortcut: a crash between the commit and this write loses the event; Platform management's nightly reconciliation
 * corrects it. Move the write into the transaction if drift shows up.
 */
class RecordSystemEvents
{
    public function __construct(private SystemEventService $events, private WorkspaceUsage $usage) {}

    public function handle(AuditRecorded $recorded): void
    {
        if (! SystemEventService::enabled()) {
            return;
        }
        $log = $recorded->log;
        $workspace = $log->workspace_id ? Workspace::find($log->workspace_id) : null;
        $meta = $log->metadata ?? [];
        $roles = fn () => ['before' => $meta['from'] ?? null, 'after' => $meta['to'] ?? null];

        $mapped = match ($log->action) {
            'workspace.created' => ['workspace.created', ['name' => $log->target_label, 'slug' => $meta['slug'] ?? null, 'owner' => $this->user($log->actor_user_id, null)]],
            'workspace.updated' => ['workspace.updated', ['changes' => ['before' => $meta['before'] ?? [], 'after' => $meta['after'] ?? []]]],
            'workspace.deleted' => ['workspace.deleted', ['workspace_id' => $log->target_id, 'slug' => $meta['slug'] ?? null]],
            'owner.added', 'owner.removed' => ['workspace.owner_changed', ['user' => $this->user($log->target_id, $log->target_label), 'change' => $log->action === 'owner.added' ? 'added' : 'removed']],
            'member.joined' => ['member.added', [
                'user' => $this->user($log->target_id, $log->target_label), 'role' => $meta['role'] ?? null,
                'billed' => $workspace ? $this->usage->memberBilled($workspace, $log->target_label) === true : false,
            ]],
            'member.removed' => ['member.removed', ['user' => $this->user($log->target_id, $log->target_label), 'role' => $meta['role'] ?? null, 'billed' => $meta['billed'] ?? false]],
            'member.role_changed' => ['member.role_changed', [
                'user' => $this->user($log->target_id, $log->target_label), 'scope' => 'workspace', 'project' => null, 'roles' => $roles(),
                'billed_change' => ['before' => $meta['billed_before'] ?? false, 'after' => $meta['billed_after'] ?? false],
            ]],
            // A project membership change only matters to billing when it flips the person's billed status.
            'project.member_role_changed', 'project.member_added', 'project.member_removed' => ($meta['billed_before'] ?? null) === ($meta['billed_after'] ?? null) ? null : ['member.role_changed', [
                'user' => $this->user($meta['user_id'] ?? null, $meta['email'] ?? null), 'scope' => 'project', 'project' => $meta['project'] ?? null,
                'roles' => match ($log->action) {
                    'project.member_added' => ['before' => null, 'after' => $meta['role'] ?? null],
                    'project.member_removed' => ['before' => $meta['role'] ?? null, 'after' => null],
                    default => $roles(),
                },
                'billed_change' => ['before' => $meta['billed_before'], 'after' => $meta['billed_after']],
            ]],
            'project.created', 'project.deleted' => [$log->action, ['project' => ['id' => $log->target_id, 'key' => $log->target_label, 'name' => $meta['name'] ?? null]]],
            default => null,
        };

        if ($mapped && ($workspace || $log->action === 'workspace.deleted')) {
            $this->events->record($mapped[0], $workspace, $mapped[1]);
        }
    }

    /** @return array{id: ?int, email: ?string, name: ?string} */
    private function user(?int $id, ?string $email): array
    {
        $user = $id ? User::find($id) : null;

        return ['id' => $id, 'email' => $user?->email ?? $email, 'name' => $user?->name];
    }
}
