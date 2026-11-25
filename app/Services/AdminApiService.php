<?php

namespace App\Services;

use App\Enums\Entitlement;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Spec 9 / 3-R18: the lookups and entitlement writes behind the internal admin API; the rest reuses WorkspaceService and Entitlements. */
class AdminApiService
{
    public function __construct(private Entitlements $entitlements) {}

    public function find(int $id): Workspace
    {
        return Workspace::with(['owners:users.id,name,email', 'entitlements'])->find($id)
            ?? throw ValidationException::withMessages(['workspace' => "No workspace with id {$id}."])->status(404);
    }

    /**
     * Every workspace in id order, changed since $filters['updated_since'] when given.
     * shortcut: updated_since reads workspaces.updated_at, which membership changes don't touch; Platform management gets those from system events (Task 6).
     */
    public function list(array $filters): LengthAwarePaginator
    {
        [$perPage, $page] = Pagination::resolve($filters['cursor'] ?? null, $filters['per_page'] ?? null, null);

        return Workspace::with(['owners:users.id,name,email', 'entitlements'])
            ->when($filters['updated_since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', Carbon::parse($since)))
            ->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function key(string $name): Entitlement
    {
        return Entitlement::tryFrom($name) ?? throw ValidationException::withMessages(['key' => Entitlement::unknown($name)])->status(404);
    }

    /** DELETE …/entitlements/{key}: only an override is removed (back to the plan value, or no row); anything else is left as it is. */
    public function removeOverride(Workspace $workspace, Entitlement $key, ?array $staff, string $reason): void
    {
        if ($workspace->entitlements()->where('key', $key->value)->value('source') === 'override') {
            $this->entitlements->unset($workspace, $key, $staff, $reason);
        }
    }
}
