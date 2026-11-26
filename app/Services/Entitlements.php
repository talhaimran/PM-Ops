<?php

namespace App\Services;

use App\Enums\Entitlement;
use App\Exceptions\PlanLimitReached;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 7.0c: per-workspace limits. No row means unlimited; gates use these, never the edition. */
class Entitlements
{
    /** Inside locked(), a refusal is logged after the rollback so the audit row survives. */
    private int $locking = 0;

    public function __construct(private WorkspaceUsage $usage, private AuditLogger $audit) {}

    /**
     * Runs $write in a transaction holding the workspace row, so the limit checks it makes and the write they guard
     * can't interleave with another request's (two simultaneous adds both passing).
     */
    public function locked(Workspace $workspace, Closure $write): mixed
    {
        $this->locking++;
        try {
            return DB::transaction(function () use ($workspace, $write) {
                Workspace::whereKey($workspace->id)->lockForUpdate()->first();

                return $write();
            });
        } catch (PlanLimitReached $e) {
            $this->logReached($workspace, $e);

            throw $e;
        } finally {
            $this->locking--;
        }
    }

    /** The cast value, or null when the workspace has no row (unlimited / enabled). */
    public function get(Workspace $workspace, Entitlement $key): int|bool|string|null
    {
        $value = $workspace->entitlements()->where('key', $key->value)->value('value');

        return $value === null ? null : $key->cast($value);
    }

    /**
     * Refuses an action that would take $key past its limit; $adding = 0 refuses only when already over (an invitation
     * accepted after the limit dropped). Logs plan_limit.reached. Call it inside locked() with the write it guards.
     */
    public function ensure(Workspace $workspace, Entitlement $key, int $adding = 1): void
    {
        // Only count when there is a limit: counting people takes three queries.
        if ($this->get($workspace, $key) !== null) {
            $this->ensureCount($workspace, $key, $this->usage->current($workspace, $key), $adding);
        }
    }

    /** ensure() for a count the caller makes, like a person's agents (agents_per_user). */
    public function ensureCount(Workspace $workspace, Entitlement $key, int $current, int $adding = 1): void
    {
        $allowed = $this->get($workspace, $key);
        if ($allowed === null) {
            return;
        }
        if ($current + $adding > $allowed) {
            $e = new PlanLimitReached($key, $allowed, $current);
            if (! $this->locking) {
                $this->logReached($workspace, $e);
            }

            throw $e;
        }
    }

    private function logReached(Workspace $workspace, PlanLimitReached $e): void
    {
        $this->audit->log('plan_limit.reached', null, ['limit' => $e->limit->value, 'allowed' => $e->allowed, 'current' => $e->current], $workspace);
    }

    /** A person moving from $now (billed, Viewer, or not counted: null) to billed or Viewer needs room there. */
    public function ensurePerson(Workspace $workspace, ?bool $now, bool $billed): void
    {
        if ($billed && $now !== true) {
            $this->ensure($workspace, Entitlement::BilledUsers);
        } elseif (! $billed && $now === null) {
            $this->ensure($workspace, Entitlement::Viewers);
        }
    }

    /**
     * Stores a value (validated for the key's type) and logs entitlement.changed when it changes. An override keeps the
     * row's plan_value, or takes the plan/default value it replaces as one. $staff ({name, email}) makes the audit actor staff, else system (3-R18).
     */
    public function set(Workspace $workspace, Entitlement $key, string|int|bool $value, string $source, ?array $staff = null, ?string $reason = null): void
    {
        [$normalized, $error] = $key->normalize($value);
        if ($error) {
            throw ValidationException::withMessages(['value' => $error]);
        }
        $before = $workspace->entitlements()->where('key', $key->value)->first();
        $attributes = ['value' => $normalized, 'source' => $source];
        // An override keeps the plan (or Free default) value it replaces, so removing it falls back to that.
        if ($source === 'override' && $before && $before->source !== 'override') {
            $attributes['plan_value'] = $before->value;
        }
        $workspace->entitlements()->updateOrCreate(['key' => $key->value], $attributes);
        $this->logChange($workspace, $key, $before, $normalized, $source, $staff, $reason);
    }

    /** Removes the row (unlimited); an override with a plan value under it falls back to that instead. */
    public function unset(Workspace $workspace, Entitlement $key, ?array $staff = null, ?string $reason = null): void
    {
        $row = $workspace->entitlements()->where('key', $key->value)->first();
        if (! $row) {
            return;
        }
        $before = clone $row;
        if ($row->source === 'override' && $row->plan_value !== null) {
            $row->update(['value' => $row->plan_value, 'source' => 'plan']);
            $this->logChange($workspace, $key, $before, $row->plan_value, 'plan', $staff, $reason);
        } else {
            $row->delete();
            $this->logChange($workspace, $key, $before, null, null, $staff, $reason);
        }
    }

    /**
     * Platform management's PUT …/entitlements: $values (key => value; null or '' means none) become the plan. Overrides
     * keep their value and only take the new plan_value; plan rows missing from $values go. Unknown keys and bad values
     * are a 422 on entitlements.{key} before anything changes.
     *
     * @param  array<string, scalar|null>  $values
     */
    public function replacePlan(Workspace $workspace, array $values, ?array $staff = null): void
    {
        $plan = [];
        $errors = [];
        foreach ($values as $name => $value) {
            $key = Entitlement::tryFrom((string) $name);
            if (! $key) {
                $errors["entitlements.{$name}"] = Entitlement::unknown((string) $name);
            } elseif ($value !== null && $value !== '') {
                [$plan[$key->value], $error] = $key->normalize($value);
                if ($error) {
                    $errors["entitlements.{$name}"] = $error;
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($workspace, $plan, $staff) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->first();
            $rows = $workspace->entitlements()->get()->keyBy('key');
            foreach ($plan as $name => $value) {
                $row = $rows[$name] ?? null;
                if ($row?->source === 'override') {
                    $this->setPlanUnderOverride($workspace, $row, $value, $staff);
                } else {
                    $workspace->entitlements()->updateOrCreate(['key' => $name], ['value' => $value, 'plan_value' => $value, 'source' => 'plan']);
                    $this->logChange($workspace, Entitlement::from($name), $row, $value, 'plan', $staff);
                }
            }
            foreach ($rows as $name => $row) {
                if (array_key_exists($name, $plan)) {
                    continue;
                }
                if ($row->source === 'plan') {
                    $row->delete();
                    $this->logChange($workspace, Entitlement::from($name), $row, null, null, $staff);
                } elseif ($row->plan_value !== null) {
                    $this->setPlanUnderOverride($workspace, $row, null, $staff);
                }
            }
        });
    }

    /**
     * The override keeps its value; the plan under it changes. Logged too (owner review of PR 5), so removing the override
     * later is explained by the audit log.
     */
    private function setPlanUnderOverride(Workspace $workspace, WorkspaceEntitlement $row, string|int|bool|null $value, ?array $staff): void
    {
        $before = $row->plan_value;
        $row->update(['plan_value' => $value]);
        $after = $row->fresh()->plan_value;
        if ($before !== $after) {
            $this->audit->staff('entitlement.changed', null, $workspace, $staff, [
                'key' => $row->key, 'before' => $row->value, 'after' => $row->value, 'source' => 'override', 'plan_before' => $before, 'plan_after' => $after,
            ]);
        }
    }

    /** One entitlement.changed row per real change (value or source), as staff when given, else system. */
    private function logChange(Workspace $workspace, Entitlement $key, ?WorkspaceEntitlement $before, ?string $after, ?string $source, ?array $staff, ?string $reason = null): void
    {
        if ($before?->value === $after && $before?->source === $source) {
            return;
        }
        $metadata = ['key' => $key->value, 'before' => $before?->value, 'after' => $after, 'source' => $source];
        $this->audit->staff('entitlement.changed', null, $workspace, $staff, $reason === null ? $metadata : [...$metadata, 'reason' => $reason]);
    }

    /** D6: a new Cloud workspace starts with the Free values. */
    public function applyFreeDefaults(Workspace $workspace): void
    {
        $workspace->entitlements()->createMany(collect(Entitlement::cases())
            ->filter(fn (Entitlement $key) => $key->free() !== null)
            ->map(fn (Entitlement $key) => ['key' => $key->value, 'value' => $key->free(), 'source' => 'default'])
            ->values()->all());
    }
}
