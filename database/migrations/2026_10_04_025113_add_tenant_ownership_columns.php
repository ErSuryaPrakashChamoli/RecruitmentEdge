<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned tables (class B) as of this migration — a frozen copy, so later changes to
     * App\Services\Tenancy\TenantSchema never change what this migration did.
     *
     * @var list<string>
     */
    private const TENANT_TABLES = [
        'ai_action_logs',
        'ai_conversations',
        'ai_document_chunks',
        'ai_documents',
        'ai_knowledge_articles',
        'ai_messages',
        'ai_query_logs',
        'ai_tool_calls',
        'ai_tool_results',
        'ai_usage_logs',
        'automation_action_executions',
        'automation_escalations',
        'automation_executions',
        'automation_rule_versions',
        'automation_rules',
        'calendar_connections',
        'candidate_applications',
        'candidate_communication_preferences',
        'candidate_communications',
        'candidate_documents',
        'candidate_duplicate_matches',
        'candidate_joinings',
        'candidate_portal_accounts',
        'candidate_sources',
        'candidate_stage_histories',
        'candidate_timeline_events',
        'candidates',
        'code_sequences',
        'communication_template_versions',
        'communication_templates',
        'departments',
        'designations',
        'employee_hierarchy',
        'employee_referrals',
        'employee_separations',
        'employees',
        'exports',
        'failed_import_rows',
        'hiring_health_snapshots',
        'hiring_memory_records',
        'hiring_outcome_snapshots',
        'hiring_outcomes',
        'hiring_risks',
        'imports',
        'integration_statuses',
        'intelligence_evidence',
        'interview_availability_slots',
        'interview_calendar_events',
        'interview_feedback',
        'interview_scheduling_invitations',
        'interview_slot_bookings',
        'interviewers',
        'interviews',
        'job_distributions',
        'job_postings',
        'locations',
        'notifications',
        'offer_letter_conversions',
        'offer_letter_template_versions',
        'offer_letter_templates',
        'offer_letters',
        'offer_revisions',
        'offer_status_histories',
        'offers',
        'outcome_insights',
        'ownership_handoffs',
        'recruiter_actions',
        'recruiter_incentive_adjustments',
        'recruiter_incentive_approvals',
        'recruiter_incentive_calculations',
        'recruiter_incentive_payments',
        'recruiter_performance_rules',
        'recruiter_performance_snapshots',
        'recruitment_campaign_requisitions',
        'recruitment_campaign_sources',
        'recruitment_campaigns',
        'recruitment_costs',
        'recruitment_daily_activities',
        'recruitment_daily_targets',
        'recruitment_followups',
        'recruitment_incentive_rules',
        'recruitment_incentive_slabs',
        'recruitment_manual_activities',
        'recruitment_pipeline_template_stages',
        'recruitment_pipeline_template_versions',
        'recruitment_pipeline_templates',
        'recruitment_rejection_reasons',
        'recruitment_requisition_approvals',
        'recruitment_requisition_recruiters',
        'recruitment_requisitions',
        'recruitment_setting_changes',
        'recruitment_settings',
        'recruitment_stage_transitions',
        'recruitment_stages',
        'rediscovery_results',
        'rediscovery_runs',
        'requisition_pipeline_stages',
        'role_dna_profiles',
        'role_dna_versions',
        'saved_table_views',
        'talent_pool_memberships',
        'talent_pools',
        'talent_signal_snapshots',
    ];

    /**
     * Tenant-attributed tables whose rows may also have no tenant (platform audit stream;
     * provider callbacks not yet matched).
     *
     * @var list<string>
     */
    private const NULLABLE_TENANT_TABLES = ['audit_logs', 'communication_webhook_events'];

    /**
     * spatie/laravel-permission tables that take tenant_id as their team key.
     *
     * @var list<string>
     */
    private const TEAM_TABLES = ['roles', 'model_has_roles', 'model_has_permissions'];

    /**
     * SaaS-1 (expand, step 2 of 4): a nullable tenant_id on every tenant-owned and
     * tenant-attributed table, indexed and referencing tenants. Nothing is backfilled here and
     * nothing becomes mandatory: the application keeps working on the old shape until the backfill
     * and the enforcing migration have run. MySQL foreign-key checks are off while the empty
     * columns are added, so each ALTER stays in place instead of copying the table.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach ([...self::TENANT_TABLES, ...self::NULLABLE_TENANT_TABLES, 'roles'] as $table) {
                if (Schema::hasColumn($table, 'tenant_id')) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->unsignedBigInteger('tenant_id')->nullable();
                    $blueprint->index('tenant_id', $table.'_tenant_idx');
                    $blueprint->foreign('tenant_id', $table.'_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
                });
            }

            foreach (['model_has_roles', 'model_has_permissions'] as $table) {
                if (Schema::hasColumn($table, 'tenant_id')) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->unsignedBigInteger('tenant_id')->nullable();
                    $blueprint->index('tenant_id', $table.'_tenant_idx');
                });
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Restore the pre-release backup to roll back (P810-A4-01); this only removes the columns
     * from a database that has not reached the enforcing migration.
     */
    public function down(): void
    {
        foreach ([...self::TENANT_TABLES, ...self::NULLABLE_TENANT_TABLES, ...self::TEAM_TABLES] as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if ($table !== 'model_has_roles' && $table !== 'model_has_permissions') {
                    Schema::getConnection()->getDriverName() === 'sqlite'
                        ? $blueprint->dropForeign(['tenant_id'])
                        : $blueprint->dropForeign($table.'_tenant_fk');
                }
                $blueprint->dropIndex($table.'_tenant_idx');
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};
