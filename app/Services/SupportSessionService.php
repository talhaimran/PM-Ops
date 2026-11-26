<?php

namespace App\Services;

use App\Models\SupportSession;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 3-R19 / 3-D4: staff view the app as a member. The one-time URL works once within 5 minutes; the session lasts the
 * minutes asked for (from the start), is read-only (EnsureWorkspaceWritable) and is logged in the workspace audit log.
 * shortcut: a Reverb socket subscribed during the session keeps receiving (empty) events until it closes; ending the
 * session doesn't disconnect it. Revoke channel access on end if events ever carry data.
 */
class SupportSessionService
{
    public const URL_MINUTES = 5;

    /** The web session key holding the support_sessions id. */
    public const SESSION_KEY = 'support_session_id';

    public function __construct(private AuditLogger $audit) {}

    /** @param array{name: string, email: string} $staff  @return string the one-time sign-in URL (its token is stored only as a hash) */
    public function start(Workspace $workspace, ?User $target, array $staff, string $reason, int $minutes): string
    {
        if (! $target?->workspaceRole($workspace)) {
            throw ValidationException::withMessages(['target_user_id' => 'The target user must be a member of this workspace.']);
        }
        $token = Str::random(64);

        DB::transaction(function () use ($workspace, $target, $staff, $reason, $minutes, $token) {
            $session = SupportSession::create([
                'workspace_id' => $workspace->id, 'target_user_id' => $target->id,
                'staff_name' => $staff['name'], 'staff_email' => $staff['email'], 'reason' => $reason,
                'token_hash' => hash('sha256', $token), 'url_expires_at' => now()->addMinutes(self::URL_MINUTES), 'ends_at' => now()->addMinutes($minutes),
            ]);
            $this->audit->staff('staff.support_session_started', $target, $workspace, $staff, ['reason' => $reason, 'minutes' => $minutes, 'ends_at' => $session->ends_at->toIso8601String()]);
        });

        return route('support-sessions.consume', $token);
    }

    /** Marks the URL used; a used, expired, ended or unknown one (or a target who left) is a plain 404. */
    public function consume(string $token): SupportSession
    {
        return DB::transaction(function () use ($token) {
            $session = SupportSession::with(['workspace', 'target'])->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_unless($session && ! $session->used_at && $session->url_expires_at->isFuture() && $session->ends_at->isFuture()
                && $session->target->workspaceRole($session->workspace), 404);
            $session->forceFill(['used_at' => now()])->save();

            return $session;
        });
    }

    /** support-sessions:end: every opened session past ends_at that nobody's browser came back to end. */
    public function endDue(): int
    {
        $due = SupportSession::with(['workspace', 'target'])->whereNotNull('used_at')->whereNull('ended_at')->where('ends_at', '<', now())->get();
        $due->each(fn (SupportSession $session) => $this->end($session));

        return $due->count();
    }

    /** At ends_at (here, in EndSupportSession or support-sessions:end) or on sign-out: staff.support_session_ended, once. */
    public function end(SupportSession $session): void
    {
        if (SupportSession::whereKey($session->id)->whereNull('ended_at')->update(['ended_at' => now()])) {
            $session->loadMissing(['workspace', 'target']);
            $this->audit->staff('staff.support_session_ended', $session->target, $session->workspace, ['name' => $session->staff_name, 'email' => $session->staff_email]);
        }
    }
}
