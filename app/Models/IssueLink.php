<?php

namespace App\Models;

use App\Enums\LinkType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Spec 7.5: one link between two issues, stored once in the forward direction (source blocks target). */
#[Fillable(['source_issue_id', 'target_issue_id', 'type', 'created_by', 'agent_id', 'via'])]
class IssueLink extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['type' => LinkType::class];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'source_issue_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'target_issue_id');
    }
}
