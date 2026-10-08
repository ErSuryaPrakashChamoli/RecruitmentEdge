<?php

use App\Services\Tenancy\TenantBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Every table that receives tenant_id (frozen copy as of this migration).
     *
     * @var list<string>
     */
    private const TABLES = [
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
        'audit_logs',
        'communication_webhook_events',
        'roles',
        'model_has_roles',
        'model_has_permissions',
    ];

    /**
     * SaaS-1 (backfill, step 3 of 4): the existing organisation becomes Tenant #1 — its name,
     * slug and locale settings come from config/tenancy.php (TENANT_ONE_*), never from a request —
     * and every existing row, role, role assignment and audit entry is assigned to it, in batches.
     * Existing staff identities become members of Tenant #1.
     *
     * A fresh install (no users, no business rows) gets no tenant here: tenants are provisioned.
     * Re-running after an interruption continues where it stopped (only null tenant_id rows are
     * touched). Business codes (CAND-, APP-, REQ-, OFR-, EMP-…) are never regenerated.
     */
    public function up(): void
    {
        $backfill = app(TenantBackfill::class);

        if (! $backfill->hasOrganisationData(self::TABLES)) {
            return;
        }

        $tenantId = $this->tenantOne();

        $backfill->run($tenantId, self::TABLES, function (string $table, int $rows): void {
            if ($rows > 0 && app()->runningInConsole() && ! app()->runningUnitTests()) {
                fwrite(STDOUT, "  tenant #1 backfill: {$table} {$rows} rows\n");
            }
        });
    }

    /**
     * Restore the pre-release backup to roll back (P810-A4-01).
     */
    public function down(): void
    {
        //
    }

    private function tenantOne(): int
    {
        $slug = (string) config('tenancy.tenant_one.slug');
        $existing = DB::table('tenants')->where('slug', $slug)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $now = now();

        return (int) DB::table('tenants')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'slug' => $slug,
            'name' => (string) config('tenancy.tenant_one.name'),
            'legal_name' => config('tenancy.tenant_one.legal_name'),
            'status' => 'active',
            'status_changed_at' => $now,
            'timezone' => (string) config('tenancy.tenant_one.timezone'),
            'locale' => (string) config('tenancy.tenant_one.locale'),
            'currency' => (string) config('tenancy.tenant_one.currency'),
            'country' => (string) config('tenancy.tenant_one.country'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
