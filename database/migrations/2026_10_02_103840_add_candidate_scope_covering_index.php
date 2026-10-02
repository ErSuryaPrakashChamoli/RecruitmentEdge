<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.9 (P89-PERF-002, ED-02 "with a supporting index"): the candidate scope builds the viewer's
 * visible set from the applications of their visible recruiters. On recruiter_id alone MySQL reads
 * every one of those application rows to check deleted_at and take candidate_id — random reads that
 * cost 1.26 s per query for a 30-employee manager at 1M candidates (EXPLAIN: range on
 * candidate_applications_recruiter_id_index, then row lookups). This index answers it from the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_applications', function (Blueprint $table): void {
            $table->index(['recruiter_id', 'deleted_at', 'candidate_id'], 'ca_recruiter_deleted_candidate_idx');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_applications', function (Blueprint $table): void {
            $table->dropIndex('ca_recruiter_deleted_candidate_idx');
        });
    }
};
