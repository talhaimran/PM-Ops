<?php

use App\Services\DuplicateLinkRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 7.5 (step 2): issue links, stored once in the forward direction. 2-D1: step 1 kept a Duplicate's original only
 * in the activity log (`duplicate_of`); each issue still resolved as Duplicate gets a `duplicates` link to it when the
 * original still exists in the same workspace (DuplicateLinkRepair, also run daily). The rest are skipped and counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-runnable without losing links made since: the table is created once, and the backfill only adds what's missing.
        if (! Schema::hasTable('issue_links')) {
            Schema::create('issue_links', function (Blueprint $table) {
                $table->id();
                // Purging an issue removes its links (FK cascade); a soft delete only hides them.
                $table->foreignId('source_issue_id')->constrained('issues')->cascadeOnDelete();
                $table->foreignId('target_issue_id')->constrained('issues')->cascadeOnDelete();
                $table->string('type', 20);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
                $table->string('via', 20);
                $table->timestamp('created_at')->useCurrent();
                // One original per Duplicate, held by MySQL: the source only on duplicates rows, unique (NULLs don't clash).
                $table->unsignedBigInteger('duplicate_source_id')->nullable()->virtualAs("IF(type = 'duplicates', source_issue_id, NULL)")->unique();
                $table->unique(['source_issue_id', 'target_issue_id', 'type']);
                // The other direction: "is blocked by" lookups and the Blocked flag.
                $table->index(['target_issue_id', 'type']);
            });
        }

        [$created, $skipped] = DuplicateLinkRepair::run();
        echo "Duplicate links: {$created} created, {$skipped} skipped (original not recorded, purged, missing, in another workspace or removed on purpose).\n";
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_links');
    }
};
