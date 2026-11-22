<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec 8 (A5): one AI connection per workspace; the key is encrypted by the model's cast and never read back out. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('model', 100);
            $table->string('endpoint', 255)->nullable();
            $table->text('key')->nullable();
            $table->unsignedBigInteger('monthly_token_cap')->nullable();
            $table->unsignedBigInteger('tokens_used_month')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
