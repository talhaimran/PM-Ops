<?php

namespace App\Models;

use App\Enums\ProjectRole;
use App\Services\CurrentWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['key', 'name', 'description'])]
class Project extends Model
{
    /** Route keys ({project:key}) resolve only inside the current workspace: another workspace's key is a plain 404. */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->where('workspace_id', app(CurrentWorkspace::class)->id() ?? 0)
            ->first();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function agentSettings(): HasOne
    {
        return $this->hasOne(ProjectAgentSettings::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function columns(): HasMany
    {
        return $this->hasMany(Column::class)->orderBy('position');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class)->orderBy('name');
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }

    /**
     * Statuses mapped to this project's columns in board order (column position, then mapping position), each with
     * its column_name. A query, not a relation: statuses reach a project through its columns.
     */
    public function statuses(): Builder
    {
        return Status::query()
            ->join('column_statuses', 'column_statuses.status_id', '=', 'statuses.id')
            ->join('columns', 'columns.id', '=', 'column_statuses.column_id')
            ->where('columns.project_id', $this->id)
            ->orderBy('columns.position')->orderBy('column_statuses.position')
            ->select('statuses.*', 'columns.name as column_name');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function roleFor(User $user): ?ProjectRole
    {
        // Reuse loaded members to avoid a query per check on pages that already have them.
        $member = $this->relationLoaded('members')
            ? $this->members->firstWhere('id', $user->id)
            : $this->members()->whereKey($user->id)->first();

        return $member ? ProjectRole::from($member->pivot->role) : null;
    }
}
