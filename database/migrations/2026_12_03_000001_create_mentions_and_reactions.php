<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec 7.10 and 7.11 (step 3, 3-R9..R13): who was @mentioned where, and reactions on comments. */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL isn't transactional: a retry after a failed run must not fail on what exists.
        if (! Schema::hasTable('mentions')) {
            Schema::create('mentions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
                $table->foreignId('comment_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('mentioned_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
                $table->string('via', 10)->default('web');
                $table->timestamp('created_at')->nullable();
                $table->index(['mentioned_user_id', 'issue_id']);
                // The per-agent hourly limit counts these.
                $table->index(['agent_id', 'created_at']);
            });
        }
        if (! Schema::hasTable('comment_reactions')) {
            Schema::create('comment_reactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
                $table->string('via', 10)->default('web');
                // Binary: in a unicode_ci column several different emoji compare equal, and the unique key below would merge them.
                $table->string('emoji', 16)->collation('utf8mb4_bin');
                // u{id} for a person, a{id} for an agent: a person and each of their agents react separately.
                $table->string('reactor', 24);
                $table->timestamp('created_at')->nullable();
                $table->unique(['comment_id', 'emoji', 'reactor']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_reactions');
        Schema::dropIfExists('mentions');
    }
};
