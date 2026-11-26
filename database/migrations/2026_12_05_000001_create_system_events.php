<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 3 PR 6 (3-R22): the outbox for events sent to Platform management. */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL isn't transactional: a retry after a failed run must not fail on what exists.
        if (Schema::hasTable('system_events')) {
            return;
        }
        Schema::create('system_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // No foreign key: workspace.deleted outlives its workspace.
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('type', 40);
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'type']);
            $table->index('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_events');
    }
};
