<?php

namespace App\Models;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\Resolution;
use App\Enums\StatusCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'project_id', 'number', 'title', 'description', 'type', 'priority', 'status_id', 'resolution', 'resolved_at',
    'assignee_id', 'reporter_id', 'story_points', 'due_date', 'epic_id', 'parent_id', 'sprint_id', 'rank',
])]
class Issue extends Model
{
    use SoftDeletes;

    /** Set by IssueService::update when an agent's move to Done was held for a person (spec 8, A2); never stored. */
    public ?ApprovalRequest $heldApproval = null;

    protected function casts(): array
    {
        return [
            'type' => IssueType::class,
            'priority' => IssuePriority::class,
            'due_date' => 'date:Y-m-d',
            'story_points' => 'integer',
            'resolution' => Resolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** "WEB-12". Needs the project relation loaded. */
    protected function key(): Attribute
    {
        return Attribute::get(fn () => $this->project->key.'-'.$this->number);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /** Completed work: only resolution Done counts (sprint progress); Won't do and the rest are Done-category but not completed. */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('resolution', Resolution::Done->value);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereHas('status', fn ($status) => $status->where('category', '!=', StatusCategory::Done));
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'epic_id');
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Issue::class, 'parent_id');
    }

    public function epicIssues(): HasMany
    {
        return $this->hasMany(Issue::class, 'epic_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** Approval requests still waiting for a person (A2), oldest first. */
    public function pendingApprovals(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class)->open()->oldest('id')->chaperone();
    }
}
