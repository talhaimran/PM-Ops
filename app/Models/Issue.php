<?php

namespace App\Models;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\LinkType;
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

    /** Set by IssueService::loadFull: the links the viewer can see, grouped by type name (spec 7.5, 2-D2); never stored. */
    public ?array $links = null;

    /** Spec 7.11: the version a new issue starts at (the column default), so a created model carries it too. */
    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return [
            'type' => IssueType::class,
            'priority' => IssuePriority::class,
            'due_date' => 'date:Y-m-d',
            'story_points' => 'integer',
            'resolution' => Resolution::class,
            'resolved_at' => 'datetime',
            'version' => 'integer',
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

    /** Issues that block this one (spec 7.5). Deleted ones drop out with the SoftDeletes scope. */
    public function blockers(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'issue_links', 'target_issue_id', 'source_issue_id')->wherePivot('type', LinkType::Blocks->value);
    }

    /** Issues this one follows (they precede it). */
    public function predecessors(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'issue_links', 'target_issue_id', 'source_issue_id')->wherePivot('type', LinkType::Precedes->value);
    }

    /** Only issues in projects $viewer can open (2-D2: links to the rest are hidden, not counted or hinted). */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->whereIn($query->qualifyColumn('project_id'), $viewer->accessibleProjects()->select('projects.id'));
    }

    /** Adds `blocked`: an unresolved blocker $viewer can see. One subquery, so a whole page costs no extra query. */
    public function scopeWithBlocked(Builder $query, User $viewer): void
    {
        $query->withExists(['blockers as blocked' => fn ($q) => $q->open()->visibleTo($viewer)]);
    }

    /** Approval requests still waiting for a person (A2), oldest first. */
    public function pendingApprovals(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class)->open()->oldest('id')->chaperone();
    }
}
