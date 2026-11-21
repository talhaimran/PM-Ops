<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Spec 8 (A4): what agents should know about one project. Written by project Admins who are people. */
#[Fillable(['project_id', 'instructions', 'mode_c_enabled'])]
class ProjectAgentSettings extends Model
{
    protected $table = 'project_agent_settings';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['mode_c_enabled' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
