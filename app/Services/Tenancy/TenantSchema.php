<?php

namespace App\Services\Tenancy;

/**
 * SaaS-1: the tenancy classification of every table (docs/saas-1-tenant-foundation.md §3).
 * TenancyArchitectureTest fails when a table exists that is not classified here, when a
 * tenant-owned table has no NOT NULL tenant_id, or when a tenant-owned model lacks
 * BelongsToTenant — a new table must be classified on purpose, never by default.
 */
final class TenantSchema
{
    /**
     * Class B: owned by exactly one tenant. tenant_id NOT NULL, leading tenant indexes, scoped
     * by BelongsToTenant (models) or by an explicit tenant_id condition (raw pivot writes).
     *
     * @var list<string>
     */
    public const TENANT_TABLES = [
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
     * Tenant-attributed rows that may also exist without a tenant:
     * - audit_logs: the tenant audit stream (tenant_id set, scoped like any tenant table) plus the
     *   platform stream (tenant_id null: sign-in attempts for unknown accounts, platform commands),
     *   which no tenant query can reach;
     * - communication_webhook_events: provider callbacks are received before their tenant is
     *   known; tenant_id is filled in from the matched message (null = not matched).
     *
     * @var list<string>
     */
    public const NULLABLE_TENANT_TABLES = [
        'audit_logs',
        'communication_webhook_events',
    ];

    /**
     * Class A: platform records. ai_evaluations / ai_evaluation_runs are the platform's own AI
     * quality suite (questions and expected tools, no tenant data).
     *
     * @var list<string>
     */
    public const PLATFORM_TABLES = [
        'tenants',
        'ai_evaluations',
        'ai_evaluation_runs',
    ];

    /**
     * Class C: global staff identity and its tenant links. roles, model_has_roles and
     * model_has_permissions carry tenant_id as the spatie/laravel-permission team key, so every
     * role belongs to one tenant; tenant_memberships links an identity to a tenant.
     *
     * @var list<string>
     */
    public const IDENTITY_TABLES = [
        'users',
        'password_reset_tokens',
        'tenant_memberships',
        'roles',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
    ];

    /**
     * Class D: shared reference data (the permission catalogue — names only).
     *
     * @var list<string>
     */
    public const REFERENCE_TABLES = [
        'permissions',
    ];

    /**
     * Class E: framework and operational tables. Their rows carry no tenant column; queued
     * payloads carry the tenant (TenantQueueGuard) and cache keys are tenant-prefixed (TenantCache).
     *
     * @var list<string>
     */
    public const SYSTEM_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'sessions',
    ];

    /**
     * Tenant-owned → tenant-owned references that are ON DELETE SET NULL. MySQL cannot make them
     * composite (it would null tenant_id), so BelongsToTenant checks them before every save and
     * `tenancy:verify` re-checks the stored data.
     *
     * @var array<string, array<string, string>>
     */
    public const REFERENCES = [
        'ai_action_logs' => [
            'conversation_id' => 'ai_conversations',
        ],
        'ai_documents' => [
            'uploaded_by' => 'employees',
        ],
        'ai_knowledge_articles' => [
            'created_by' => 'employees',
        ],
        'ai_usage_logs' => [
            'conversation_id' => 'ai_conversations',
        ],
        'automation_escalations' => [
            'recipient_employee_id' => 'employees',
            'recruiter_action_id' => 'recruiter_actions',
        ],
        'automation_executions' => [
            'automation_rule_version_id' => 'automation_rule_versions',
            'candidate_application_id' => 'candidate_applications',
            'parent_execution_id' => 'automation_executions',
            'recruiter_id' => 'employees',
        ],
        'candidate_applications' => [
            'campaign_id' => 'recruitment_campaigns',
            'dropout_reason_id' => 'recruitment_rejection_reasons',
            'job_posting_id' => 'job_postings',
            'pipeline_stage_id' => 'requisition_pipeline_stages',
            'rejection_reason_id' => 'recruitment_rejection_reasons',
        ],
        'candidate_communications' => [
            'candidate_application_id' => 'candidate_applications',
            'communication_template_id' => 'communication_templates',
            'communication_template_version_id' => 'communication_template_versions',
            'interview_id' => 'interviews',
            'requisition_id' => 'recruitment_requisitions',
            'sent_by' => 'employees',
        ],
        'candidate_documents' => [
            'verified_by' => 'employees',
        ],
        'candidate_duplicate_matches' => [
            'resolved_by' => 'employees',
        ],
        'candidate_joinings' => [
            'created_by' => 'employees',
            'dropout_reason_id' => 'recruitment_rejection_reasons',
            'offer_id' => 'offers',
        ],
        'candidate_portal_accounts' => [
            'invited_by' => 'employees',
        ],
        'candidate_stage_histories' => [
            'changed_by' => 'employees',
            'new_pipeline_stage_id' => 'requisition_pipeline_stages',
            'previous_pipeline_stage_id' => 'requisition_pipeline_stages',
        ],
        'candidate_timeline_events' => [
            'candidate_application_id' => 'candidate_applications',
            'candidate_joining_id' => 'candidate_joinings',
            'interview_id' => 'interviews',
            'offer_id' => 'offers',
            'requisition_id' => 'recruitment_requisitions',
        ],
        'candidates' => [
            'created_by' => 'employees',
            'referral_employee_id' => 'employees',
        ],
        'communication_template_versions' => [
            'created_by' => 'employees',
        ],
        'communication_templates' => [
            'created_by' => 'employees',
            'updated_by' => 'employees',
        ],
        'designations' => [
            'department_id' => 'departments',
        ],
        'employee_referrals' => [
            'candidate_application_id' => 'candidate_applications',
            'created_by' => 'employees',
            'incentive_calculation_id' => 'recruiter_incentive_calculations',
            'rejection_reason_id' => 'recruitment_rejection_reasons',
            'requisition_id' => 'recruitment_requisitions',
            'reviewed_by' => 'employees',
            'source_id' => 'candidate_sources',
        ],
        'employees' => [
            'candidate_id' => 'candidates',
            'location_id' => 'locations',
            'reports_to_id' => 'employees',
        ],
        'hiring_memory_records' => [
            'candidate_application_id' => 'candidate_applications',
            'department_id' => 'departments',
            'designation_id' => 'designations',
            'requisition_id' => 'recruitment_requisitions',
            'supersedes_id' => 'hiring_memory_records',
        ],
        'hiring_outcome_snapshots' => [
            'candidate_application_id' => 'candidate_applications',
            'candidate_id' => 'candidates',
            'candidate_joining_id' => 'candidate_joinings',
            'department_id' => 'departments',
            'designation_id' => 'designations',
            'employee_id' => 'employees',
            'location_id' => 'locations',
            'requisition_id' => 'recruitment_requisitions',
            'role_dna_version_id' => 'role_dna_versions',
            'source_id' => 'candidate_sources',
        ],
        'hiring_outcomes' => [
            'candidate_application_id' => 'candidate_applications',
            'candidate_joining_id' => 'candidate_joinings',
            'employee_id' => 'employees',
            'hiring_outcome_snapshot_id' => 'hiring_outcome_snapshots',
            'offer_id' => 'offers',
            'requisition_id' => 'recruitment_requisitions',
            'supersedes_id' => 'hiring_outcomes',
        ],
        'hiring_risks' => [
            'candidate_application_id' => 'candidate_applications',
            'owner_id' => 'employees',
            'recruiter_action_id' => 'recruiter_actions',
        ],
        'integration_statuses' => [
            'last_tested_by' => 'employees',
        ],
        'interview_availability_slots' => [
            'cancelled_by' => 'employees',
            'created_by' => 'employees',
            'requisition_id' => 'recruitment_requisitions',
        ],
        'interview_calendar_events' => [
            'calendar_connection_id' => 'calendar_connections',
        ],
        'interview_feedback' => [
            'supersedes_id' => 'interview_feedback',
        ],
        'interview_scheduling_invitations' => [
            'created_by' => 'employees',
            'interviewer_id' => 'employees',
        ],
        'interview_slot_bookings' => [
            'interview_id' => 'interviews',
            'invitation_id' => 'interview_scheduling_invitations',
            'rescheduled_from_id' => 'interview_slot_bookings',
        ],
        'interviews' => [
            'created_by' => 'employees',
            'rejection_reason_id' => 'recruitment_rejection_reasons',
        ],
        'job_postings' => [
            'created_by' => 'employees',
            'updated_by' => 'employees',
        ],
        'offer_letter_conversions' => [
            'issued_by' => 'employees',
        ],
        'offer_letter_template_versions' => [
            'created_by' => 'employees',
        ],
        'offer_letter_templates' => [
            'created_by' => 'employees',
        ],
        'offer_letters' => [
            'issued_by' => 'employees',
        ],
        'offer_revisions' => [
            'designation_id' => 'designations',
            'location_id' => 'locations',
        ],
        'offer_status_histories' => [
            'changed_by' => 'employees',
        ],
        'offers' => [
            'created_by' => 'employees',
            'designation_id' => 'designations',
            'location_id' => 'locations',
            'offer_letter_template_id' => 'offer_letter_templates',
        ],
        'outcome_insights' => [
            'designation_id' => 'designations',
            'requisition_id' => 'recruitment_requisitions',
        ],
        'ownership_handoffs' => [
            'assignee_employee_id' => 'employees',
            'employee_id' => 'employees',
        ],
        'recruiter_actions' => [
            'automation_execution_id' => 'automation_executions',
            'automation_rule_id' => 'automation_rules',
            'candidate_application_id' => 'candidate_applications',
            'candidate_id' => 'candidates',
            'completed_by' => 'employees',
            'created_by' => 'employees',
            'owner_id' => 'employees',
            'requisition_id' => 'recruitment_requisitions',
        ],
        'recruiter_incentive_adjustments' => [
            'created_by' => 'employees',
        ],
        'recruiter_incentive_approvals' => [
            'changed_by' => 'employees',
        ],
        'recruiter_incentive_calculations' => [
            'created_by' => 'employees',
            'employee_referral_id' => 'employee_referrals',
            'incentive_slab_id' => 'recruitment_incentive_slabs',
        ],
        'recruiter_incentive_payments' => [
            'paid_by' => 'employees',
        ],
        'recruiter_performance_rules' => [
            'created_by' => 'employees',
        ],
        'recruitment_campaigns' => [
            'created_by' => 'employees',
            'owner_id' => 'employees',
        ],
        'recruitment_costs' => [
            'campaign_id' => 'recruitment_campaigns',
            'created_by' => 'employees',
        ],
        'recruitment_daily_activities' => [
            'candidate_application_id' => 'candidate_applications',
            'candidate_id' => 'candidates',
            'created_by' => 'employees',
        ],
        'recruitment_daily_targets' => [
            'created_by' => 'employees',
        ],
        'recruitment_followups' => [
            'created_by' => 'employees',
        ],
        'recruitment_incentive_rules' => [
            'created_by' => 'employees',
        ],
        'recruitment_manual_activities' => [
            'created_by' => 'employees',
        ],
        'recruitment_pipeline_template_versions' => [
            'created_by' => 'employees',
        ],
        'recruitment_pipeline_templates' => [
            'cloned_from_id' => 'recruitment_pipeline_templates',
            'created_by' => 'employees',
        ],
        'recruitment_requisition_approvals' => [
            'changed_by' => 'employees',
        ],
        'recruitment_requisitions' => [
            'assistant_manager_id' => 'employees',
            'created_by' => 'employees',
            'hiring_manager_id' => 'employees',
            'location_id' => 'locations',
            'manager_id' => 'employees',
            'pipeline_applied_by' => 'employees',
            'pipeline_template_id' => 'recruitment_pipeline_templates',
            'reporting_manager_id' => 'employees',
            'vp_hr_id' => 'employees',
        ],
        'recruitment_stages' => [
            'created_by' => 'employees',
        ],
        'rediscovery_runs' => [
            'role_dna_version_id' => 'role_dna_versions',
        ],
        'requisition_pipeline_stages' => [
            'recruitment_stage_id' => 'recruitment_stages',
        ],
        'role_dna_profiles' => [
            'designation_id' => 'designations',
        ],
        'talent_pool_memberships' => [
            'added_by' => 'employees',
            'removed_by' => 'employees',
        ],
        'talent_pools' => [
            'archived_by' => 'employees',
            'created_by' => 'employees',
            'department_id' => 'departments',
            'owner_id' => 'employees',
        ],
        'talent_signal_snapshots' => [
            'candidate_application_id' => 'candidate_applications',
            'role_dna_version_id' => 'role_dna_versions',
        ],
    ];

    /**
     * Tenant-owned → tenant-owned references enforced by the database as composite foreign keys
     * (tenant_id, column) → parent (tenant_id, id), with the original ON DELETE rule.
     *
     * @var array<string, array<string, string>>
     */
    public const COMPOSITE_REFERENCES = [
        'ai_messages' => [
            'conversation_id' => 'ai_conversations',
        ],
        'ai_tool_calls' => [
            'message_id' => 'ai_messages',
        ],
        'ai_tool_results' => [
            'tool_call_id' => 'ai_tool_calls',
        ],
        'automation_action_executions' => [
            'automation_execution_id' => 'automation_executions',
        ],
        'automation_escalations' => [
            'automation_execution_id' => 'automation_executions',
            'automation_rule_id' => 'automation_rules',
        ],
        'automation_executions' => [
            'automation_rule_id' => 'automation_rules',
        ],
        'automation_rule_versions' => [
            'automation_rule_id' => 'automation_rules',
        ],
        'calendar_connections' => [
            'employee_id' => 'employees',
        ],
        'candidate_applications' => [
            'candidate_id' => 'candidates',
            'recruiter_id' => 'employees',
            'requisition_id' => 'recruitment_requisitions',
        ],
        'candidate_communication_preferences' => [
            'candidate_id' => 'candidates',
        ],
        'candidate_communications' => [
            'candidate_id' => 'candidates',
        ],
        'candidate_documents' => [
            'candidate_id' => 'candidates',
            'candidate_joining_id' => 'candidate_joinings',
        ],
        'candidate_duplicate_matches' => [
            'candidate_id' => 'candidates',
            'matched_candidate_id' => 'candidates',
        ],
        'candidate_joinings' => [
            'candidate_application_id' => 'candidate_applications',
        ],
        'candidate_portal_accounts' => [
            'candidate_id' => 'candidates',
        ],
        'candidate_stage_histories' => [
            'candidate_application_id' => 'candidate_applications',
        ],
        'candidate_timeline_events' => [
            'candidate_id' => 'candidates',
        ],
        'candidates' => [
            'source_id' => 'candidate_sources',
        ],
        'communication_template_versions' => [
            'communication_template_id' => 'communication_templates',
        ],
        'employee_hierarchy' => [
            'ancestor_id' => 'employees',
            'descendant_id' => 'employees',
        ],
        'employee_referrals' => [
            'candidate_id' => 'candidates',
            'referrer_id' => 'employees',
        ],
        'employee_separations' => [
            'employee_id' => 'employees',
        ],
        'employees' => [
            'department_id' => 'departments',
            'designation_id' => 'designations',
        ],
        'failed_import_rows' => [
            'import_id' => 'imports',
        ],
        'hiring_health_snapshots' => [
            'requisition_id' => 'recruitment_requisitions',
        ],
        'hiring_risks' => [
            'requisition_id' => 'recruitment_requisitions',
        ],
        'interview_availability_slots' => [
            'interviewer_id' => 'employees',
        ],
        'interview_calendar_events' => [
            'interview_id' => 'interviews',
        ],
        'interview_feedback' => [
            'interview_id' => 'interviews',
            'interviewer_id' => 'employees',
        ],
        'interview_scheduling_invitations' => [
            'candidate_application_id' => 'candidate_applications',
        ],
        'interview_slot_bookings' => [
            'candidate_application_id' => 'candidate_applications',
            'candidate_id' => 'candidates',
            'slot_id' => 'interview_availability_slots',
        ],
        'interviewers' => [
            'employee_id' => 'employees',
        ],
        'interviews' => [
            'candidate_application_id' => 'candidate_applications',
            'interviewer_id' => 'employees',
        ],
        'job_distributions' => [
            'job_posting_id' => 'job_postings',
        ],
        'job_postings' => [
            'requisition_id' => 'recruitment_requisitions',
        ],
        'offer_letter_conversions' => [
            'offer_id' => 'offers',
            'offer_letter_id' => 'offer_letters',
            'offer_letter_template_id' => 'offer_letter_templates',
            'offer_letter_template_version_id' => 'offer_letter_template_versions',
            'offer_revision_id' => 'offer_revisions',
        ],
        'offer_letter_template_versions' => [
            'offer_letter_template_id' => 'offer_letter_templates',
        ],
        'offer_letters' => [
            'offer_id' => 'offers',
            'offer_letter_template_id' => 'offer_letter_templates',
            'offer_letter_template_version_id' => 'offer_letter_template_versions',
            'offer_revision_id' => 'offer_revisions',
        ],
        'offer_revisions' => [
            'offer_id' => 'offers',
        ],
        'offer_status_histories' => [
            'offer_id' => 'offers',
        ],
        'offers' => [
            'candidate_application_id' => 'candidate_applications',
        ],
        'recruiter_incentive_adjustments' => [
            'recruiter_incentive_calculation_id' => 'recruiter_incentive_calculations',
        ],
        'recruiter_incentive_approvals' => [
            'recruiter_incentive_calculation_id' => 'recruiter_incentive_calculations',
        ],
        'recruiter_incentive_calculations' => [
            'candidate_application_id' => 'candidate_applications',
            'candidate_id' => 'candidates',
            'employee_id' => 'employees',
            'incentive_rule_id' => 'recruitment_incentive_rules',
        ],
        'recruiter_incentive_payments' => [
            'recruiter_incentive_calculation_id' => 'recruiter_incentive_calculations',
        ],
        'recruiter_performance_snapshots' => [
            'employee_id' => 'employees',
        ],
        'recruitment_campaign_requisitions' => [
            'recruitment_campaign_id' => 'recruitment_campaigns',
            'requisition_id' => 'recruitment_requisitions',
        ],
        'recruitment_campaign_sources' => [
            'recruitment_campaign_id' => 'recruitment_campaigns',
            'source_id' => 'candidate_sources',
        ],
        'recruitment_costs' => [
            'department_id' => 'departments',
            'location_id' => 'locations',
            'requisition_id' => 'recruitment_requisitions',
            'source_id' => 'candidate_sources',
        ],
        'recruitment_daily_activities' => [
            'recruiter_id' => 'employees',
        ],
        'recruitment_daily_targets' => [
            'department_id' => 'departments',
            'designation_id' => 'designations',
            'employee_id' => 'employees',
        ],
        'recruitment_followups' => [
            'candidate_application_id' => 'candidate_applications',
            'recruiter_id' => 'employees',
        ],
        'recruitment_incentive_rules' => [
            'department_id' => 'departments',
            'designation_id' => 'designations',
            'employee_id' => 'employees',
            'location_id' => 'locations',
        ],
        'recruitment_incentive_slabs' => [
            'incentive_rule_id' => 'recruitment_incentive_rules',
        ],
        'recruitment_manual_activities' => [
            'recruiter_id' => 'employees',
        ],
        'recruitment_pipeline_template_stages' => [
            'pipeline_template_id' => 'recruitment_pipeline_templates',
            'recruitment_stage_id' => 'recruitment_stages',
        ],
        'recruitment_pipeline_template_versions' => [
            'pipeline_template_id' => 'recruitment_pipeline_templates',
        ],
        'recruitment_requisition_approvals' => [
            'requisition_id' => 'recruitment_requisitions',
        ],
        'recruitment_requisition_recruiters' => [
            'employee_id' => 'employees',
            'requisition_id' => 'recruitment_requisitions',
        ],
        'recruitment_requisitions' => [
            'department_id' => 'departments',
            'designation_id' => 'designations',
        ],
        'recruitment_stage_transitions' => [
            'from_stage_id' => 'recruitment_stages',
            'to_stage_id' => 'recruitment_stages',
        ],
        'rediscovery_results' => [
            'candidate_id' => 'candidates',
            'rediscovery_run_id' => 'rediscovery_runs',
        ],
        'rediscovery_runs' => [
            'requisition_id' => 'recruitment_requisitions',
        ],
        'requisition_pipeline_stages' => [
            'requisition_id' => 'recruitment_requisitions',
        ],
        'role_dna_profiles' => [
            'requisition_id' => 'recruitment_requisitions',
        ],
        'role_dna_versions' => [
            'role_dna_profile_id' => 'role_dna_profiles',
        ],
        'talent_pool_memberships' => [
            'candidate_id' => 'candidates',
            'talent_pool_id' => 'talent_pools',
        ],
        'talent_signal_snapshots' => [
            'candidate_id' => 'candidates',
            'requisition_id' => 'recruitment_requisitions',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function classifiedTables(): array
    {
        return [
            ...self::TENANT_TABLES,
            ...self::NULLABLE_TENANT_TABLES,
            ...self::PLATFORM_TABLES,
            ...self::IDENTITY_TABLES,
            ...self::REFERENCE_TABLES,
            ...self::SYSTEM_TABLES,
        ];
    }

    public static function isTenantOwned(string $table): bool
    {
        return in_array($table, self::TENANT_TABLES, true);
    }
}
