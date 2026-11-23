<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\Issue;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class ActivityLogger
{
    public const CHANGES_CAP = 200;

    /** Container key set by the MCP server so activity records "mcp" instead of "api". */
    public const VIA_BINDING = 'pm.via';

    /** Container key set while a person decides an approval request, so the entry links the agent and the request. */
    private const APPROVAL_BINDING = 'pm.approval';

    /** @param array<string, array{from: mixed, to: mixed}>|null $changes */
    public function log(Issue $issue, ?User $user, string $action, ?array $changes = null): Activity
    {
        $approval = self::approval();
        if ($approval) {
            // shortcut: the request link lives in `changes` (no activities column in step A); move it to a column with agent_run_id (step B).
            $changes = [...($changes ?? []), 'approval_request' => ['id' => $approval->id, 'to' => $approval->action['to_status']]];
        }

        return $issue->activities()->create([
            'action' => $action,
            'changes' => $changes,
            // The issue's version after this change (spec 7.11), so changesSince() is one query.
            'issue_version' => $issue->version,
            // An OAuth token has no name of its own: it is the agent's.
            'token_name' => self::token($user)?->name ?? $user?->agent()?->name,
            ...self::attribution($user),
        ]);
    }

    /**
     * Spec 7.11: what changed on $issue after $version, one entry per field: the value it had first, the value it has
     * now, and who made that last change ("{Owner}'s agent" when an agent did). Only $issue's own rows are read.
     *
     * @return list<array{field: string, from: mixed, to: mixed, by: ?string, agent: ?string, via: string, at: string}>
     */
    public function changesSince(Issue $issue, int $version, ?bool &$truncated = null): array
    {
        $changes = [];
        // Only the newest CHANGES_CAP rows are read; $truncated says older ones were left out, so a field's `from` is then
        // its value before the oldest row read. The conflict itself is decided by the version, never by this list.
        $rows = $issue->activities()->with(['user', 'agent.owner'])->where('issue_version', '>', $version)->whereNotNull('changes')
            ->orderByDesc('id')->limit(self::CHANGES_CAP + 1)->get();
        $truncated = $rows->count() > self::CHANGES_CAP;
        $rows = $rows->take(self::CHANGES_CAP)->reverse();
        foreach ($rows as $row) {
            // approval_request is the link to an agent's request (A2), not a field; a person decided it, so no agent acted.
            $approval = isset($row->changes['approval_request']);
            foreach (collect($row->changes)->except('approval_request') as $field => $change) {
                $from = isset($changes[$field]) ? $changes[$field]['from'] : $change['from'];
                // Moved to the end, so the last entry is always the newest change (the dialog's "Changed by").
                unset($changes[$field]);
                $changes[$field] = [
                    'field' => $field,
                    'from' => $from,
                    'to' => $change['to'],
                    'by' => $row->user?->name,
                    'agent' => $row->agent && ! $approval ? self::agentLabel($row->agent) : null,
                    'via' => $row->via,
                    'at' => $row->created_at->toIso8601String(),
                ];
            }
        }

        return array_values($changes);
    }

    /** "Alex's agent": the owner's first name, as the UI's AgentLabel shows it (spec 5.1). */
    public static function agentLabel(Agent $agent): string
    {
        return $agent->owner ? strtok($agent->owner->name, ' ')."'s agent" : $agent->name;
    }

    /** "Sam moved WEB-1 to Done" or "Sam's agent changed title, priority on WEB-1": changesSince() in one line. */
    public static function describe(array $changes, string $key): string
    {
        $last = end($changes);
        $who = $last ? ($last['agent'] ?? $last['by'] ?? 'Someone') : 'Someone';
        $status = collect($changes)->firstWhere('field', 'status');

        return match (true) {
            ! $changes => "{$key} changed",
            $status !== null => "{$who} moved {$key} to {$status['to']}",
            // Field names as the activity log shows them ("story points").
            default => "{$who} changed ".collect($changes)->pluck('field')->map(fn ($f) => str_replace('_', ' ', $f))->implode(', ')." on {$key}",
        };
    }

    /** Who, which agent and how: the one source for activity and history rows. */
    public static function attribution(?User $user): array
    {
        return ['user_id' => $user?->id, 'agent_id' => self::approval()?->agent_id ?? $user?->agent()?->id, 'via' => self::via($user)];
    }

    private static function approval(): ?ApprovalRequest
    {
        return app()->bound(self::APPROVAL_BINDING) ? app(self::APPROVAL_BINDING) : null;
    }

    /** Run $decide with its activity entries made by the decider but linked to the request and the agent that asked (A2). */
    public static function forApproval(ApprovalRequest $approval, callable $decide): mixed
    {
        app()->instance(self::APPROVAL_BINDING, $approval);
        try {
            return $decide();
        } finally {
            app()->forgetInstance(self::APPROVAL_BINDING);
        }
    }

    /** How the change came in: mcp (set by the MCP server), api (a bearer token), else web. AuditLogger uses it too. */
    public static function via(?User $user): string
    {
        return app()->bound(self::VIA_BINDING) ? app(self::VIA_BINDING) : ($user?->viaToken() ? 'api' : 'web');
    }

    public static function token(?User $user): ?PersonalAccessToken
    {
        $token = $user?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }
}
