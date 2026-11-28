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

    /** B-R6: whether runs in this role apply their changes directly: built-in roles by the workspace's agent_role_apply setting, custom ones by apply_mode. */
    public function appliesDirectly(Workspace $workspace): bool
    {
        return ($this->workspace_id === null ? ($workspace->setting('agent_role_apply') ?? [])[$this->id] ?? 'propose' : $this->apply_mode) === 'apply';
    }
}
