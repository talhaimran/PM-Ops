<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec 8 (A4): per-project notes for agents, and (step A5) whether Mode C may run here. No row means the defaults. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_agent_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('instructions')->nullable();
            $table->boolean('mode_c_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_agent_settings');
    }
};
