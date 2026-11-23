<?php

use App\Enums\ApprovalStatus;
use App\Enums\StatusCategory;
use App\Models\Column;
use App\Models\Workspace;
use App\Services\StatusService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 7.4 (step 1): the workspace status library. Each column gets a library status of its name (first column
 * to_do, last done, others in_progress; a one-column project's column is done, 1-R1); a name already used with
 * another category becomes "Name (KEY)". Issues take their column's status and issues.column_id goes (1-D2).
 * Approval requests are converted to statuses (1-R6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('category');
            $table->string('color', 20)->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('column_statuses', function (Blueprint $table) {
            $table->foreignId('column_id')->constrained()->cascadeOnDelete();
            $table->foreignId('status_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unique(['column_id', 'status_id']);
            $table->index('status_id');
        });

        Schema::table('issues', fn (Blueprint $table) => $table->unsignedBigInteger('status_id')->nullable()->after('column_id'));

        $statuses = app(StatusService::class);
        $suffixed = [];
        foreach (Workspace::orderBy('id')->get() as $workspace) {
            foreach ($workspace->projects()->orderBy('id')->get() as $project) {
                $columns = Column::where('project_id', $project->id)->orderBy('position')->orderBy('id')->get();
                foreach ($columns as $i => $column) {
                    $category = match (true) {
                        $i === $columns->count() - 1 => StatusCategory::Done,
                        $i === 0 => StatusCategory::ToDo,
                        default => StatusCategory::InProgress,
                    };
                    $status = $statuses->forColumn($workspace, $column->name, $category, $project->key);
                    $statuses->attach($column->setRelation('project', $project), $status);
                    if ($status->wasRecentlyCreated && strcasecmp($status->name, $column->name) !== 0) {
                        $suffixed[] = $status->name;
                    }
                    // Soft-deleted issues too: the query builder has no SoftDeletes scope.
                    DB::table('issues')->where('column_id', $column->id)->update(['status_id' => $status->id]);
                }
            }
        }

        Schema::table('issues', function (Blueprint $table) {
            $table->unsignedBigInteger('status_id')->nullable(false)->change();
            $table->foreign('status_id')->references('id')->on('statuses')->restrictOnDelete();
            // Before dropping (project_id, column_id): the project_id foreign key needs an index that starts with it.
            $table->index(['project_id', 'status_id']);
        });
        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['column_id']);
            $table->dropIndex(['project_id', 'column_id']);
            $table->dropColumn('column_id');
        });

        $stale = [];
        DB::table('approval_requests')->orderBy('id')->chunkById(200, function ($rows) use (&$stale) {
            foreach ($rows as $row) {
                $action = json_decode($row->action, true);
                $to = $this->statusOf($action['to_column_id'] ?? null);
                $update = ['action' => json_encode([
                    'to_status_id' => $to?->id,
                    'to_status' => $to?->name ?? $action['to_column'],
                    'from_status_id' => $this->statusOf($action['from_column_id'] ?? null)?->id,
                    'reason' => $action['reason'] ?? null,
                ])];
                if (! $to && $row->status === ApprovalStatus::Pending->value) {
                    $update += ['status' => ApprovalStatus::Stale->value, 'note' => "The column {$action['to_column']} was deleted."];
                    $stale[] = $row->id;
                }
                DB::table('approval_requests')->where('id', $row->id)->update($update);
            }
        });

        // Spec 7.4: the renamed statuses go in PROGRESS.md, so say which ones were made.
        if (! app()->runningUnitTests()) {
            echo $suffixed ? '  Statuses created with a project key: '.implode(', ', $suffixed).PHP_EOL : '';
            echo $stale ? '  Approval requests marked stale (column deleted): '.implode(', ', $stale).PHP_EOL : '';
        }
    }

    public function down(): void
    {
        Schema::table('issues', fn (Blueprint $table) => $table->unsignedBigInteger('column_id')->nullable()->after('status_id'));
        DB::statement('update issues join column_statuses on column_statuses.status_id = issues.status_id
            join columns on columns.id = column_statuses.column_id and columns.project_id = issues.project_id
            set issues.column_id = columns.id');
        // A status unmapped since the migration: the project's first column, so no issue is lost.
        DB::statement('update issues set column_id = (select id from columns where columns.project_id = issues.project_id order by position limit 1) where column_id is null');
        Schema::table('issues', function (Blueprint $table) {
            $table->unsignedBigInteger('column_id')->nullable(false)->change();
            $table->foreign('column_id')->references('id')->on('columns')->restrictOnDelete();
            $table->index(['project_id', 'column_id']);
        });

        DB::table('approval_requests')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $action = json_decode($row->action, true);
                $column = fn (?int $status) => $status ? DB::table('column_statuses')->join('columns', 'columns.id', '=', 'column_statuses.column_id')
                    ->join('issues', 'issues.project_id', '=', 'columns.project_id')->where('issues.id', $row->issue_id)
                    ->where('column_statuses.status_id', $status)->value('columns.id') : null;
                DB::table('approval_requests')->where('id', $row->id)->update(['action' => json_encode([
                    'to_column_id' => $column($action['to_status_id'] ?? null),
                    'to_column' => $action['to_status'],
                    'from_column_id' => $column($action['from_status_id'] ?? null),
                    'reason' => $action['reason'] ?? null,
                ])]);
            }
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
            $table->dropIndex(['project_id', 'status_id']);
            $table->dropColumn('status_id');
        });
        Schema::dropIfExists('column_statuses');
        Schema::dropIfExists('statuses');
    }

    /** The status a step A column was mapped to above (one per column), or null when the column is gone. */
    private function statusOf(?int $columnId): ?object
    {
        return $columnId ? DB::table('column_statuses')->join('statuses', 'statuses.id', '=', 'column_statuses.status_id')
            ->where('column_statuses.column_id', $columnId)->first(['statuses.id', 'statuses.name']) : null;
    }
};
