<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-D3: an OAuth token acts as exactly one agent, picked on the consent screen. The code carries the choice to the
 * token exchange; a refreshed token inherits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['oauth_auth_codes', 'oauth_access_tokens'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('agent_id')->nullable()->after('client_id')->constrained()->nullOnDelete());
        }
    }

    public function down(): void
    {
        foreach (['oauth_auth_codes', 'oauth_access_tokens'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('agent_id'));
        }
    }
};
