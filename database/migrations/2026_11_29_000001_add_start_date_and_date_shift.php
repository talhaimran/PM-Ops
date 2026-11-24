<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec 7.5 (step 2): `issues.start_date`, and each person's choice for shifting successors (ask, always or never). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('due_date')->index();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('date_shift', 10)->default('ask');
        });
    }

    public function down(): void
    {
        Schema::table('issues', fn (Blueprint $table) => $table->dropColumn('start_date'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('date_shift'));
    }
};
