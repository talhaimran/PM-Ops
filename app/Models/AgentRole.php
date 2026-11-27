<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Step B (B-R5): what an in-app run is told and which tools it may call. No workspace = a built-in role. */
#[Fillable(['workspace_id', 'name', 'instructions', 'allowed_tools', 'apply_mode'])]
class AgentRole extends Model
{
    protected function casts(): array
    {
        return ['allowed_tools' => 'array'];
    }
}
