<?php

namespace App\Services;

use App\Enums\Entitlement;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/** 3-R23/3-R24: the Owners' Billing link (a signed 5-minute token) and what the billing banners show. */
class BillingLink
{
    public const TTL = 300;

    public const NOTICES = ['past_due', 'canceling', 'read_only'];

    public function __construct(private Entitlements $entitlements) {}

    public static function enabled(): bool
    {
        return (bool) config('pm.billing_url') && (bool) config('pm.billing_link_secret');
    }

    /** PM_BILLING_URL/session?token=base64url(claims).base64url(HMAC-SHA256 of the first part); Platform management checks the nonce is unused. */
    public function url(Workspace $workspace, User $owner): string
    {
        $encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $claims = $encode(json_encode([
            'workspace_id' => $workspace->id, 'user_id' => $owner->id, 'email' => $owner->email, 'name' => $owner->name,
            'role' => $owner->workspaceRole($workspace)?->value, 'iat' => $now = now()->timestamp, 'exp' => $now + self::TTL,
            'nonce' => bin2hex(random_bytes(32)),
        ]));

        return rtrim(config('pm.billing_url'), '/').'/session?token='.$claims.'.'.$encode(hash_hmac('sha256', $claims, config('pm.billing_link_secret'), true));
    }

    /**
     * The shared `billing` prop (3-R24). Owners see the trial countdown, past_due, canceling and the link;
     * everyone sees read_only. shortcut: no date for canceling, no key carries one (spec gap); enforcement stays with Platform management.
     *
     * @return array{link: ?string, trial_days: ?int, notice: ?string}
     */
    public function banners(Workspace $workspace, User $user): array
    {
        $owner = $user->workspaceRole($workspace) === WorkspaceRole::Owner;
        $notice = $this->entitlements->get($workspace, Entitlement::BillingNotice);
        $ends = $owner ? $this->entitlements->get($workspace, Entitlement::TrialEndsAt) : null;
        $days = $ends ? (int) today()->diffInDays(Carbon::parse($ends)->startOfDay(), false) : null;

        return [
            'link' => $owner && self::enabled() ? route('settings.billing', [], false) : null,
            'trial_days' => $days !== null && $days >= 0 ? $days : null,
            'notice' => in_array($notice, self::NOTICES, true) && ($owner || $notice === 'read_only') ? $notice : null,
        ];
    }
}
