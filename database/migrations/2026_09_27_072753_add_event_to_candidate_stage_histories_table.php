<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.5 (DF-9): what each stage-history row records — a stage entry, or a status/assignment
 * change that leaves the stage as it was (App\Enums\StageHistoryEvent). Nullable and additive:
 * rows written before Phase 8.5 keep a null event and are classified when read; they are never
 * rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_stage_histories', function (Blueprint $table) {
            $table->string('event', 32)->nullable()->after('new_stage');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_stage_histories', function (Blueprint $table) {
            $table->dropColumn('event');
        });
    }
};
