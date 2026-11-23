<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 7.13 (step 1): append-only history for reports. Backfilled from the activity log, mapping the old column
 * names the log holds to the project's statuses; status changes whose name no longer maps are skipped and counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1-R9: a deleted status leaves history standing; the row keeps to_category.
        Schema::create('issue_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('to_category');
            $this->who($table);
            $table->index(['issue_id', 'created_at']);
            $table->index(['to_status_id', 'created_at']);
        });

        Schema::create('sprint_scope_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('event', 10);
            // shortcut: integer points only, hours with halves arrive with the estimation unit in step 10.
            $table->unsignedInteger('estimate')->nullable();
            $this->who($table);
            $table->index(['sprint_id', 'created_at']);
        });

        Schema::create('estimate_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            // shortcut: integer points only, hours with halves arrive with the estimation unit in step 10.
            $table->unsignedInteger('from_estimate')->nullable();
            $table->unsignedInteger('to_estimate')->nullable();
            $this->who($table);
            $table->index(['issue_id', 'created_at']);
        });

        $skipped = 0;
        // Soft-deleted issues too: the query builder has no SoftDeletes scope.
        DB::table('issues')->select('id', 'project_id', 'status_id', 'sprint_id', 'story_points', 'reporter_id', 'created_at')
            ->eachById(function ($issue) use (&$skipped) {
                $skipped += $this->backfill($issue);
            }, 200);

        echo "History backfill: skipped {$skipped} status or sprint changes whose name no longer maps in its project.\n";
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_history');
        Schema::dropIfExists('sprint_scope_events');
        Schema::dropIfExists('issue_status_history');
    }

    private function who(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
        $table->string('via', 20);
        $table->timestamp('created_at')->useCurrent();
    }

    /** @var array<int, array{statuses: array<string, object>, sprints: array<string, int>}> per project */
    private array $names = [];

    /** @return int rows skipped */
    private function backfill(object $issue): int
    {
        $names = $this->names[$issue->project_id] ??= [
            'statuses' => DB::table('column_statuses')->join('columns', 'columns.id', '=', 'column_statuses.column_id')->join('statuses', 'statuses.id', '=', 'column_statuses.status_id')
                ->where('columns.project_id', $issue->project_id)->get(['statuses.id', 'statuses.name', 'statuses.category'])->keyBy('name')->all(),
            'sprints' => DB::table('sprints')->where('project_id', $issue->project_id)->pluck('id', 'name')->all(),
        ];
        $current = DB::table('statuses')->find($issue->status_id);
        $activities = DB::table('activities')->where('issue_id', $issue->id)->orderBy('id')->get(['action', 'changes', 'user_id', 'agent_id', 'via', 'created_at'])
            ->map(function ($a) {
                $a->changes = json_decode($a->changes ?? 'null', true) ?? [];

                return $a;
            });
        $created = $activities->firstWhere('action', 'created');
        $at = fn ($a) => ['user_id' => $a?->user_id, 'agent_id' => $a?->agent_id, 'via' => $a?->via ?? 'web', 'created_at' => $a?->created_at ?? $issue->created_at];
        $initial = ['user_id' => $created?->user_id ?? $issue->reporter_id, 'agent_id' => $created?->agent_id, 'via' => $created?->via ?? 'web', 'created_at' => $issue->created_at];

        $statusRows = $sprintRows = $estimateRows = [];
        $skipped = 0;

        // Where it started: the first change's "from", else where it is now.
        $firstStatus = $activities->first(fn ($a) => isset($a->changes['status']));
        $start = $firstStatus ? ($names['statuses'][$firstStatus->changes['status']['from']] ?? null) : $current;
        if ($start) {
            $statusRows[] = ['issue_id' => $issue->id, 'from_status_id' => null, 'to_status_id' => $start->id, 'to_category' => $start->category, ...$initial];
        }
        $firstEstimate = $activities->first(fn ($a) => isset($a->changes['story_points']));
        $estimate = $firstEstimate ? $firstEstimate->changes['story_points']['from'] : $issue->story_points;
        if ($estimate !== null) {
            $estimateRows[] = ['issue_id' => $issue->id, 'from_estimate' => null, 'to_estimate' => $estimate, ...$initial];
        }
        $firstSprint = $activities->first(fn ($a) => isset($a->changes['sprint']));
        $sprint = $firstSprint ? ($names['sprints'][$firstSprint->changes['sprint']['from']] ?? null) : $issue->sprint_id;
        if ($sprint) {
            $sprintRows[] = ['sprint_id' => $sprint, 'issue_id' => $issue->id, 'event' => 'joined', 'estimate' => $estimate, ...$initial];
        }

        foreach ($activities as $a) {
            if (isset($a->changes['story_points'])) {
                $to = $a->changes['story_points']['to'];
                $estimateRows[] = ['issue_id' => $issue->id, 'from_estimate' => $a->changes['story_points']['from'], 'to_estimate' => $to, ...$at($a)];
                $estimate = $to;
            }
            if (isset($a->changes['status'])) {
                $from = $names['statuses'][$a->changes['status']['from']] ?? null;
                $to = $names['statuses'][$a->changes['status']['to']] ?? null;
                if ($from && $to) {
                    $statusRows[] = ['issue_id' => $issue->id, 'from_status_id' => $from->id, 'to_status_id' => $to->id, 'to_category' => $to->category, ...$at($a)];
                } else {
                    $skipped++;
                }
            }
            if (isset($a->changes['sprint'])) {
                foreach (['left' => 'from', 'joined' => 'to'] as $event => $side) {
                    $name = $a->changes['sprint'][$side];
                    $id = $name === null ? null : ($names['sprints'][$name] ?? null);
                    if ($id) {
                        $sprintRows[] = ['sprint_id' => $id, 'issue_id' => $issue->id, 'event' => $event, 'estimate' => $estimate, ...$at($a)];
                    } elseif ($name !== null) {
                        $skipped++;
                    }
                }
            }
        }

        DB::table('issue_status_history')->insert($statusRows);
        DB::table('sprint_scope_events')->insert($sprintRows);
        DB::table('estimate_history')->insert($estimateRows);

        return $skipped;
    }
};
