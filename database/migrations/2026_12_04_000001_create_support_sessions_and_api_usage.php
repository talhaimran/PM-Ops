<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 3 PR 5: support sessions (3-R19), API calls per workspace per day (3-R20), and a plan's value under an override. */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL isn't transactional: a retry after a failed run must not fail on what exists.
        if (! Schema::hasTable('support_sessions')) {
            Schema::create('support_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('staff_name');
                $table->string('staff_email');
                $table->string('reason', 500);
                // sha256 of the one-time URL's token; the token itself is never stored.
                $table->string('token_hash', 64)->unique();
                $table->timestamp('url_expires_at');
                $table->timestamp('ends_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('workspace_api_usage')) {
            Schema::create('workspace_api_usage', function (Blueprint $table) {
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->date('day');
                $table->unsignedInteger('calls')->default(0);
                $table->unique(['workspace_id', 'day']);
            });
        }
        if (! Schema::hasColumn('workspace_entitlements', 'plan_value')) {
            // The plan's value while an override is in force, so removing the override falls back to it.
            Schema::table('workspace_entitlements', fn (Blueprint $table) => $table->string('plan_value')->nullable()->after('value'));
        }
    }

    public function down(): void
    {
        Schema::table('workspace_entitlements', fn (Blueprint $table) => $table->dropColumn('plan_value'));
        Schema::dropIfExists('workspace_api_usage');
        Schema::dropIfExists('support_sessions');
    }
};
