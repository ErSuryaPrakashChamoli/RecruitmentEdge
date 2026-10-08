<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS-7 (C7): tenant-led indexes, only where EXPLAIN on a copy with two large tenants (100k
 * candidates each) and a small one showed a plan walking the whole tenant, or other tenants' rows
 * (docs/saas-7-capacity-plan.md, index study):
 *
 * - candidates, candidate_applications (tenant_id, updated_at): the API's `updated_since` filter
 *   (ordered by id) walked the tenant's rows by primary key — 459 ms and 325 ms for a large tenant;
 *   1.4 ms and 1.2 ms with the index.
 * - candidate_stage_histories (tenant_id, created_at): stage-activity metrics over a period ranged
 *   over every tenant's histories of that period — 38 → 14 ms for a large tenant, 13.9 → 0.9 ms for
 *   a small one: the cost now follows the tenant's own volume.
 * - audit_logs (tenant_id, created_at): a small tenant's audit list (newest first) scanned the
 *   created_at index backwards through other tenants' rows — 144 → 0.5 ms.
 *
 * Measured and not added (no material gain): (tenant_id, current_stage, id), (tenant_id, status, id).
 * MySQL 8.4 adds a secondary index in place without blocking writes; measured on the copy: 0.2–2.0 s
 * each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->index(['tenant_id', 'updated_at'], 'candidates_tenant_updated_idx');
        });

        Schema::table('candidate_applications', function (Blueprint $table): void {
            $table->index(['tenant_id', 'updated_at'], 'ca_tenant_updated_idx');
        });

        Schema::table('candidate_stage_histories', function (Blueprint $table): void {
            $table->index(['tenant_id', 'created_at'], 'csh_tenant_created_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['tenant_id', 'created_at'], 'audit_logs_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_tenant_created_idx');
        });

        Schema::table('candidate_stage_histories', function (Blueprint $table): void {
            $table->dropIndex('csh_tenant_created_idx');
        });

        Schema::table('candidate_applications', function (Blueprint $table): void {
            $table->dropIndex('ca_tenant_updated_idx');
        });

        Schema::table('candidates', function (Blueprint $table): void {
            $table->dropIndex('candidates_tenant_updated_idx');
        });
    }
};
