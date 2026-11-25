<?php

namespace App\Http\Resources\Internal;

use App\Enums\Entitlement;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\WorkspaceUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/contract/admin-api/workspace.json. Needs owners and entitlements loaded (AdminApiService).
 * shortcut: usage is computed per workspace (about ten queries each), so a list page of 100 runs ~1000; batch it if reconciliation gets slow.
 *
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'state' => $this->state(),
            'owners' => $this->owners->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values()->all(),
            'entitlements' => (object) $this->entitlements->mapWithKeys(fn (WorkspaceEntitlement $row) => [$row->key => [
                'value' => self::cast($row->key, $row->value),
                'source' => $row->source,
                'plan_value' => self::cast($row->key, $row->plan_value),
            ]])->all(),
            'usage' => app(WorkspaceUsage::class)->usage($this->resource),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private static function cast(string $key, ?string $value): int|bool|string|null
    {
        return $value === null ? null : (Entitlement::tryFrom($key)?->cast($value) ?? $value);
    }
}
