<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec 7.10 (step 3, 3-R4..R8): a person's inbox. Model InboxItem (not Laravel's notification channel). */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL isn't transactional: a retry after a failed run must not fail on what exists.
        if (Schema::hasTable('notifications')) {
            return;
        }
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('via', 10)->default('web');
            // list of {action, from, to}
            $table->json('changes');
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'workspace_id', 'read_at']);
            $table->index(['user_id', 'issue_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
