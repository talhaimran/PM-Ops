<?php

namespace App\Models;

use App\Enums\StatusCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['project_id', 'name', 'position'])]
class Column extends Model
{
    /** New projects' columns, each mapped to a library status of the same name and this category. */
    public const DEFAULTS = ['To Do' => StatusCategory::ToDo, 'In Progress' => StatusCategory::InProgress, 'In Review' => StatusCategory::InProgress, 'Done' => StatusCategory::Done];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Statuses shown in this column; the first is where a dropped card lands (1-R13: in the order they were added). */
    public function statuses(): BelongsToMany
    {
        return $this->belongsToMany(Status::class, 'column_statuses')->withPivot('position')->orderByPivot('position');
    }

    /** Issues whose status maps to this column. Statuses are shared, so only this column's project counts. */
    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'column_statuses', 'column_id', 'status_id', 'id', 'status_id')
            ->whereIn('issues.project_id', self::select('project_id')->whereColumn('columns.id', 'column_statuses.column_id'));
    }
}
