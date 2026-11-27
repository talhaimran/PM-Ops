<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step B: activity, inbox and audit rows can name the in-app run that caused them. */
return new class extends Migration
{
    private const TABLES = ['activities', 'notifications', 'audit_logs'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            // MySQL DDL isn't transactional: a retry after a failed run must not fail on what exists.
            if (! Schema::hasColumn($name, 'agent_run_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('agent_run_id'));
        }
    }
};
