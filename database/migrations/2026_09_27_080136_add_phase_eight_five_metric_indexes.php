<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.5 (D45, PF-7): additive indexes for the governed metrics' period filters. Each serves a
 * query that scanned the whole table at 100k applications (docs/phase-8-5-discovery.md §26):
 *
 * - candidate_stage_histories (new_stage, created_at): stage entries in a period (funnel, stage
 *   activity, SLA legs, recruiter stage actuals).
 * - candidate_applications (application_date): the application cohort (funnel, source, conversion).
 * - offers (offer_date): offer volume and recruiter offer actuals.
 * - candidate_joinings (actual_doj): hires, time to hire, cost per hire, join rate.
 *
 * Write overhead is one extra B-tree entry per insert on each table; none of these columns is
 * updated in place except actual_doj (set once when a joining is marked Joined). Rollback drops
 * the indexes only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_stage_histories', function (Blueprint $table) {
            $table->index(['new_stage', 'created_at'], 'csh_stage_created_idx');
        });

        Schema::table('candidate_applications', function (Blueprint $table) {
            $table->index('application_date', 'ca_application_date_idx');
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->index('offer_date', 'offers_offer_date_idx');
        });

        Schema::table('candidate_joinings', function (Blueprint $table) {
            $table->index('actual_doj', 'cj_actual_doj_idx');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_stage_histories', fn (Blueprint $table) => $table->dropIndex('csh_stage_created_idx'));
        Schema::table('candidate_applications', fn (Blueprint $table) => $table->dropIndex('ca_application_date_idx'));
        Schema::table('offers', fn (Blueprint $table) => $table->dropIndex('offers_offer_date_idx'));
        Schema::table('candidate_joinings', fn (Blueprint $table) => $table->dropIndex('cj_actual_doj_idx'));
    }
};
