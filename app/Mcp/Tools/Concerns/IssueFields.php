<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\Resolution;
use Illuminate\Contracts\JsonSchema\JsonSchema;

trait IssueFields
{
    /** Writable issue fields shared by create_issue and update_issue. */
    protected function issueFields(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->description('Markdown description. @ plus a project member\'s email mentions and notifies them; the response may carry `warnings` (`mention_not_member`, `mention_rate_limited`).'),
            'type' => $schema->string()->enum(IssueType::class)->description('Default task.'),
            'priority' => $schema->string()->enum(IssuePriority::class)->description('Default medium.'),
            'status' => $schema->string()->description('Status name, e.g. "To Do", "In Progress" (a column name works when the column has one status). Case-insensitive.'),
            'resolution' => $schema->string()->enum(Resolution::class)->description('How a Done-category issue ended; only with a Done status (or on an issue already Done). Default done when moving into one. duplicate needs duplicate_of.'),
            'duplicate_of' => $schema->string()->description('Key of the original issue, required with resolution duplicate, e.g. WEB-3.'),
            'assignee' => $schema->string()->nullable()->description('Assignee email (must be a project member); null to unassign.'),
            'labels' => $schema->array()->items($schema->string())->description('Label names; replaces the current labels. Labels must exist.'),
            'story_points' => $schema->integer()->min(0)->max(1000)->nullable(),
            'due_date' => $schema->string()->format('date')->nullable()->description('YYYY-MM-DD.'),
            'start_date' => $schema->string()->format('date')->nullable()->description('YYYY-MM-DD; not after due_date.'),
            'epic' => $schema->string()->nullable()->description('Key of an epic in the same project, e.g. WEB-3; null to unlink.'),
            'parent' => $schema->string()->nullable()->description('Key of the parent issue to make this a sub-task (one level deep); null to detach.'),
            'sprint' => $schema->string()->nullable()->description('Name (or id) of a planned or active sprint; null for the backlog.'),
        ];
    }
}
