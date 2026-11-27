<?php

namespace App\Models;

use App\Enums\AgentRunChangeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step B (B-R18): one change a run proposed (kind field, child_issue, link or comment), with the issue version it saw. */
#[Fillable(['run_id', 'kind', 'target', 'before', 'after', 'issue_version', 'status', 'message'])]
class AgentRunChange extends Model
{
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'issue_version' => 'integer',
            'status' => AgentRunChangeStatus::class,
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }
}
