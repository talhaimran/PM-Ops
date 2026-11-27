<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Step B (B-R16/B-R18): agent roles, in-app agent runs and the changes a run proposes; the four built-in roles (B-R5). */
return new class extends Migration
{
    /** B-R5: name => [allowed tools, instructions], from spec 8's role table. */
    private const BUILT_IN = [
        'Triage' => [['read_issue', 'search_issues', 'propose_fields', 'propose_link'],
            'You triage a new bug. Check it for missing information such as steps to reproduce, expected and actual behaviour, and environment, and say what is missing. Suggest its type, priority and labels, and point out likely duplicates.'],
        'Writer' => [['read_issue', 'propose_fields'],
            'You write and tighten issues. Draft or rewrite the description so it is clear and short, and add acceptance criteria someone can check.'],
        'Planner' => [['read_issue', 'search_issues', 'propose_child_issue', 'propose_link'],
            'You plan work. Break an epic or story into small child issues, each with a clear title, a short description and an estimate.'],
        'Summariser' => [['read_issue', 'search_issues'],
            'You summarise. Read the issue and its thread and catch the reader up: what it is about, what was decided, and what is still open.'],
    ];

    public function up(): void
    {
        // MySQL DDL isn't transactional: a retry after a failed run must not fail on what exists.
        if (! Schema::hasTable('agent_roles')) {
            Schema::create('agent_roles', function (Blueprint $table) {
                $table->id();
                // Null: a built-in role every workspace has.
                $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->text('instructions');
                $table->json('allowed_tools');
                $table->string('apply_mode')->default('propose');
                $table->timestamps();
            });
        }
        if (! DB::table('agent_roles')->whereNull('workspace_id')->exists()) {
            $now = now();
            DB::table('agent_roles')->insert(collect(self::BUILT_IN)->map(fn ($role, $name) => [
                'name' => $name, 'instructions' => $role[1], 'allowed_tools' => json_encode($role[0]), 'apply_mode' => 'propose', 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }
        if (! Schema::hasTable('agent_runs')) {
            Schema::create('agent_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                // The key and role name stay readable after the issue or role is gone.
                $table->foreignId('issue_id')->nullable()->constrained()->nullOnDelete();
                $table->string('issue_key');
                // Account deletion hard-deletes the person; the run stays so the month's usage doesn't drop.
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('role_id')->nullable()->constrained('agent_roles')->nullOnDelete();
                $table->string('role_name');
                $table->text('instruction')->nullable();
                $table->string('status');
                $table->json('steps')->nullable();
                $table->unsignedInteger('tokens_in')->default(0);
                $table->unsignedInteger('tokens_out')->default(0);
                $table->string('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
                $table->index(['workspace_id', 'created_at']);
            });
        }
        if (! Schema::hasTable('agent_run_changes')) {
            Schema::create('agent_run_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('run_id')->constrained('agent_runs')->cascadeOnDelete();
                $table->string('kind');
                $table->string('target')->nullable();
                $table->json('before')->nullable();
                $table->json('after');
                $table->unsignedInteger('issue_version')->nullable();
                $table->string('status');
                $table->string('message')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_run_changes');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_roles');
    }
};
