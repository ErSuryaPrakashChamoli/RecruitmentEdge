<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.9 (P89-PERF-011, P89-PERF-013): only the indexes an EXPLAIN on the 100k benchmark showed a
 * full scan or a filesort for, on queries the application runs on every render or dashboard.
 *
 * - candidate_applications (status, current_stage, last_activity_at): each pipeline column
 *   ("stage X, active, newest activity first", LIMIT 15) and its count filesorted every matching
 *   application; candidate ageing and the application.stuck sweep filter the same columns.
 * - candidate_stage_histories (created_at): stage-activity, reached-stage and recruiter-activity
 *   metrics range on created_at without new_stage; csh_stage_created_idx leads with new_stage.
 * - audit_logs (created_at) and (action, created_at): the audit list sorts newest first and filters
 *   by action; both filesorted the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_applications', function (Blueprint $table): void {
            $table->index(['status', 'current_stage', 'last_activity_at'], 'ca_status_stage_activity_idx');
        });

        Schema::table('candidate_stage_histories', function (Blueprint $table): void {
            $table->index('created_at', 'csh_created_at_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index('created_at', 'audit_logs_created_at_idx');
            $table->index(['action', 'created_at'], 'audit_logs_action_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_action_created_idx');
            $table->dropIndex('audit_logs_created_at_idx');
        });

        Schema::table('candidate_stage_histories', function (Blueprint $table): void {
            $table->dropIndex('csh_created_at_idx');
        });

        Schema::table('candidate_applications', function (Blueprint $table): void {
            $table->dropIndex('ca_status_stage_activity_idx');
        });
    }
};
