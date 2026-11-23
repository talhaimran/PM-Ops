<?php

namespace App\Models;

use App\Enums\StatusCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Spec 7.4: a workspace library status. Columns map to statuses (column_statuses); issues have one. */
#[Fillable(['workspace_id', 'name', 'category', 'color', 'description'])]
class Status extends Model
{
    protected function casts(): array
    {
        return ['category' => StatusCategory::class];
    }

    public function isDone(): bool
    {
        return $this->category === StatusCategory::Done;
    }
}
