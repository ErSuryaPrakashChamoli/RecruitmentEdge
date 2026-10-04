<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
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
     * Tenant → tenant references that become composite foreign keys:
     * [child table, column, parent table, ON DELETE rule, short name].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const COMPOSITE_REFERENCES = [
        ['ai_messages', 'conversation_id', 'ai_conversations', 'cascade', 'ai_messages_conversation_id'],
        ['ai_tool_calls', 'message_id', 'ai_messages', 'cascade', 'ai_tool_calls_message_id'],
        ['ai_tool_results', 'tool_call_id', 'ai_tool_calls', 'cascade', 'ai_tool_results_tool_call_id'],
        ['automation_action_executions', 'automation_execution_id', 'automation_executions', 'cascade', 'automation_action_executions_automation_execution_id'],
        ['automation_escalations', 'automation_execution_id', 'automation_executions', 'cascade', 'automation_escalations_automation_execution_id'],
        ['automation_escalations', 'automation_rule_id', 'automation_rules', 'cascade', 'automation_escalations_automation_rule_id'],
        ['automation_executions', 'automation_rule_id', 'automation_rules', 'cascade', 'automation_executions_automation_rule_id'],
        ['automation_rule_versions', 'automation_rule_id', 'automation_rules', 'cascade', 'automation_rule_versions_automation_rule_id'],
        ['calendar_connections', 'employee_id', 'employees', 'cascade', 'calendar_connections_employee_id'],
        ['candidate_applications', 'candidate_id', 'candidates', 'restrict', 'candidate_applications_candidate_id'],
        ['candidate_applications', 'recruiter_id', 'employees', 'restrict', 'candidate_applications_recruiter_id'],
        ['candidate_applications', 'requisition_id', 'recruitment_requisitions', 'restrict', 'candidate_applications_requisition_id'],
        ['candidate_communication_preferences', 'candidate_id', 'candidates', 'cascade', 'candidate_communication_preferences_candidate_id'],
        ['candidate_communications', 'candidate_id', 'candidates', 'cascade', 'candidate_communications_candidate_id'],
        ['candidate_documents', 'candidate_id', 'candidates', 'cascade', 'candidate_documents_candidate_id'],
        ['candidate_documents', 'candidate_joining_id', 'candidate_joinings', 'cascade', 'candidate_documents_candidate_joining_id'],
        ['candidate_duplicate_matches', 'candidate_id', 'candidates', 'cascade', 'candidate_duplicate_matches_candidate_id'],
        ['candidate_duplicate_matches', 'matched_candidate_id', 'candidates', 'cascade', 'candidate_duplicate_matches_matched_candidate_id'],
        ['candidate_joinings', 'candidate_application_id', 'candidate_applications', 'restrict', 'candidate_joinings_candidate_application_id'],
        ['candidate_portal_accounts', 'candidate_id', 'candidates', 'cascade', 'candidate_portal_accounts_candidate_id'],
        ['candidate_stage_histories', 'candidate_application_id', 'candidate_applications', 'cascade', 'candidate_stage_histories_candidate_application_id'],
        ['candidate_timeline_events', 'candidate_id', 'candidates', 'cascade', 'candidate_timeline_events_candidate_id'],
        ['candidates', 'source_id', 'candidate_sources', 'restrict', 'candidates_source_id'],
        ['communication_template_versions', 'communication_template_id', 'communication_templates', 'cascade', 'communication_template_versions_46784f2c'],
        ['employee_hierarchy', 'ancestor_id', 'employees', 'cascade', 'employee_hierarchy_ancestor_id'],
        ['employee_hierarchy', 'descendant_id', 'employees', 'cascade', 'employee_hierarchy_descendant_id'],
        ['employee_referrals', 'candidate_id', 'candidates', 'restrict', 'employee_referrals_candidate_id'],
        ['employee_referrals', 'referrer_id', 'employees', 'restrict', 'employee_referrals_referrer_id'],
        ['employee_separations', 'employee_id', 'employees', 'cascade', 'employee_separations_employee_id'],
        ['employees', 'department_id', 'departments', 'restrict', 'employees_department_id'],
        ['employees', 'designation_id', 'designations', 'restrict', 'employees_designation_id'],
        ['failed_import_rows', 'import_id', 'imports', 'cascade', 'failed_import_rows_import_id'],
        ['hiring_health_snapshots', 'requisition_id', 'recruitment_requisitions', 'cascade', 'hiring_health_snapshots_requisition_id'],
        ['hiring_risks', 'requisition_id', 'recruitment_requisitions', 'cascade', 'hiring_risks_requisition_id'],
        ['interview_availability_slots', 'interviewer_id', 'employees', 'restrict', 'interview_availability_slots_interviewer_id'],
        ['interview_calendar_events', 'interview_id', 'interviews', 'cascade', 'interview_calendar_events_interview_id'],
        ['interview_feedback', 'interview_id', 'interviews', 'cascade', 'interview_feedback_interview_id'],
        ['interview_feedback', 'interviewer_id', 'employees', 'restrict', 'interview_feedback_interviewer_id'],
        ['interview_scheduling_invitations', 'candidate_application_id', 'candidate_applications', 'cascade', 'interview_scheduling_invitations_03c3f759'],
        ['interview_slot_bookings', 'candidate_application_id', 'candidate_applications', 'cascade', 'interview_slot_bookings_candidate_application_id'],
        ['interview_slot_bookings', 'candidate_id', 'candidates', 'cascade', 'interview_slot_bookings_candidate_id'],
        ['interview_slot_bookings', 'slot_id', 'interview_availability_slots', 'restrict', 'interview_slot_bookings_slot_id'],
        ['interviewers', 'employee_id', 'employees', 'cascade', 'interviewers_employee_id'],
        ['interviews', 'candidate_application_id', 'candidate_applications', 'cascade', 'interviews_candidate_application_id'],
        ['interviews', 'interviewer_id', 'employees', 'restrict', 'interviews_interviewer_id'],
        ['job_distributions', 'job_posting_id', 'job_postings', 'cascade', 'job_distributions_job_posting_id'],
        ['job_postings', 'requisition_id', 'recruitment_requisitions', 'cascade', 'job_postings_requisition_id'],
        ['offer_letter_conversions', 'offer_id', 'offers', 'restrict', 'offer_letter_conversions_offer_id'],
        ['offer_letter_conversions', 'offer_letter_id', 'offer_letters', 'restrict', 'offer_letter_conversions_offer_letter_id'],
        ['offer_letter_conversions', 'offer_letter_template_id', 'offer_letter_templates', 'restrict', 'offer_letter_conversions_offer_letter_template_id'],
        ['offer_letter_conversions', 'offer_letter_template_version_id', 'offer_letter_template_versions', 'restrict', 'offer_letter_conversions_5c52285a'],
        ['offer_letter_conversions', 'offer_revision_id', 'offer_revisions', 'restrict', 'offer_letter_conversions_offer_revision_id'],
        ['offer_letter_template_versions', 'offer_letter_template_id', 'offer_letter_templates', 'restrict', 'offer_letter_template_versions_offer_letter_template_id'],
        ['offer_letters', 'offer_id', 'offers', 'restrict', 'offer_letters_offer_id'],
        ['offer_letters', 'offer_letter_template_id', 'offer_letter_templates', 'restrict', 'offer_letters_offer_letter_template_id'],
        ['offer_letters', 'offer_letter_template_version_id', 'offer_letter_template_versions', 'restrict', 'offer_letters_offer_letter_template_version_id'],
        ['offer_letters', 'offer_revision_id', 'offer_revisions', 'restrict', 'offer_letters_offer_revision_id'],
        ['offer_revisions', 'offer_id', 'offers', 'restrict', 'offer_revisions_offer_id'],
        ['offer_status_histories', 'offer_id', 'offers', 'cascade', 'offer_status_histories_offer_id'],
        ['offers', 'candidate_application_id', 'candidate_applications', 'restrict', 'offers_candidate_application_id'],
        ['recruiter_incentive_adjustments', 'recruiter_incentive_calculation_id', 'recruiter_incentive_calculations', 'cascade', 'recruiter_incentive_adjustments_f2ce9f93'],
        ['recruiter_incentive_approvals', 'recruiter_incentive_calculation_id', 'recruiter_incentive_calculations', 'cascade', 'recruiter_incentive_approvals_ee27d559'],
        ['recruiter_incentive_calculations', 'candidate_application_id', 'candidate_applications', 'restrict', 'recruiter_incentive_calculations_a09f49d5'],
        ['recruiter_incentive_calculations', 'candidate_id', 'candidates', 'restrict', 'recruiter_incentive_calculations_candidate_id'],
        ['recruiter_incentive_calculations', 'employee_id', 'employees', 'restrict', 'recruiter_incentive_calculations_employee_id'],
        ['recruiter_incentive_calculations', 'incentive_rule_id', 'recruitment_incentive_rules', 'restrict', 'recruiter_incentive_calculations_incentive_rule_id'],
        ['recruiter_incentive_payments', 'recruiter_incentive_calculation_id', 'recruiter_incentive_calculations', 'restrict', 'recruiter_incentive_payments_32ad15cd'],
        ['recruiter_performance_snapshots', 'employee_id', 'employees', 'cascade', 'recruiter_performance_snapshots_employee_id'],
        ['recruitment_campaign_requisitions', 'recruitment_campaign_id', 'recruitment_campaigns', 'cascade', 'recruitment_campaign_requisitions_f25ec437'],
        ['recruitment_campaign_requisitions', 'requisition_id', 'recruitment_requisitions', 'cascade', 'recruitment_campaign_requisitions_requisition_id'],
        ['recruitment_campaign_sources', 'recruitment_campaign_id', 'recruitment_campaigns', 'cascade', 'recruitment_campaign_sources_recruitment_campaign_id'],
        ['recruitment_campaign_sources', 'source_id', 'candidate_sources', 'cascade', 'recruitment_campaign_sources_source_id'],
        ['recruitment_costs', 'department_id', 'departments', 'cascade', 'recruitment_costs_department_id'],
        ['recruitment_costs', 'location_id', 'locations', 'cascade', 'recruitment_costs_location_id'],
        ['recruitment_costs', 'requisition_id', 'recruitment_requisitions', 'cascade', 'recruitment_costs_requisition_id'],
        ['recruitment_costs', 'source_id', 'candidate_sources', 'cascade', 'recruitment_costs_source_id'],
        ['recruitment_daily_activities', 'recruiter_id', 'employees', 'restrict', 'recruitment_daily_activities_recruiter_id'],
        ['recruitment_daily_targets', 'department_id', 'departments', 'cascade', 'recruitment_daily_targets_department_id'],
        ['recruitment_daily_targets', 'designation_id', 'designations', 'cascade', 'recruitment_daily_targets_designation_id'],
        ['recruitment_daily_targets', 'employee_id', 'employees', 'cascade', 'recruitment_daily_targets_employee_id'],
        ['recruitment_followups', 'candidate_application_id', 'candidate_applications', 'cascade', 'recruitment_followups_candidate_application_id'],
        ['recruitment_followups', 'recruiter_id', 'employees', 'restrict', 'recruitment_followups_recruiter_id'],
        ['recruitment_incentive_rules', 'department_id', 'departments', 'cascade', 'recruitment_incentive_rules_department_id'],
        ['recruitment_incentive_rules', 'designation_id', 'designations', 'cascade', 'recruitment_incentive_rules_designation_id'],
        ['recruitment_incentive_rules', 'employee_id', 'employees', 'cascade', 'recruitment_incentive_rules_employee_id'],
        ['recruitment_incentive_rules', 'location_id', 'locations', 'cascade', 'recruitment_incentive_rules_location_id'],
        ['recruitment_incentive_slabs', 'incentive_rule_id', 'recruitment_incentive_rules', 'cascade', 'recruitment_incentive_slabs_incentive_rule_id'],
        ['recruitment_manual_activities', 'recruiter_id', 'employees', 'restrict', 'recruitment_manual_activities_recruiter_id'],
        ['recruitment_pipeline_template_stages', 'pipeline_template_id', 'recruitment_pipeline_templates', 'cascade', 'recruitment_pipeline_template_stages_94853a98'],
        ['recruitment_pipeline_template_stages', 'recruitment_stage_id', 'recruitment_stages', 'restrict', 'recruitment_pipeline_template_stages_60a685f9'],
        ['recruitment_pipeline_template_versions', 'pipeline_template_id', 'recruitment_pipeline_templates', 'restrict', 'recruitment_pipeline_template_versions_2d855644'],
        ['recruitment_requisition_approvals', 'requisition_id', 'recruitment_requisitions', 'cascade', 'recruitment_requisition_approvals_requisition_id'],
        ['recruitment_requisition_recruiters', 'employee_id', 'employees', 'cascade', 'recruitment_requisition_recruiters_employee_id'],
        ['recruitment_requisition_recruiters', 'requisition_id', 'recruitment_requisitions', 'cascade', 'recruitment_requisition_recruiters_requisition_id'],
        ['recruitment_requisitions', 'department_id', 'departments', 'restrict', 'recruitment_requisitions_department_id'],
        ['recruitment_requisitions', 'designation_id', 'designations', 'restrict', 'recruitment_requisitions_designation_id'],
        ['recruitment_stage_transitions', 'from_stage_id', 'recruitment_stages', 'cascade', 'recruitment_stage_transitions_from_stage_id'],
        ['recruitment_stage_transitions', 'to_stage_id', 'recruitment_stages', 'cascade', 'recruitment_stage_transitions_to_stage_id'],
        ['rediscovery_results', 'candidate_id', 'candidates', 'cascade', 'rediscovery_results_candidate_id'],
        ['rediscovery_results', 'rediscovery_run_id', 'rediscovery_runs', 'cascade', 'rediscovery_results_rediscovery_run_id'],
        ['rediscovery_runs', 'requisition_id', 'recruitment_requisitions', 'cascade', 'rediscovery_runs_requisition_id'],
        ['requisition_pipeline_stages', 'requisition_id', 'recruitment_requisitions', 'cascade', 'requisition_pipeline_stages_requisition_id'],
        ['role_dna_profiles', 'requisition_id', 'recruitment_requisitions', 'cascade', 'role_dna_profiles_requisition_id'],
        ['role_dna_versions', 'role_dna_profile_id', 'role_dna_profiles', 'cascade', 'role_dna_versions_role_dna_profile_id'],
        ['talent_pool_memberships', 'candidate_id', 'candidates', 'cascade', 'talent_pool_memberships_candidate_id'],
        ['talent_pool_memberships', 'talent_pool_id', 'talent_pools', 'cascade', 'talent_pool_memberships_talent_pool_id'],
        ['talent_signal_snapshots', 'candidate_id', 'candidates', 'cascade', 'talent_signal_snapshots_candidate_id'],
        ['talent_signal_snapshots', 'requisition_id', 'recruitment_requisitions', 'cascade', 'talent_signal_snapshots_requisition_id'],
    ];

    /**
     * Tenant → tenant ON DELETE SET NULL references (checked by the application, validated here).
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const CHECKED_REFERENCES = [
        ['ai_action_logs', 'conversation_id', 'ai_conversations'],
        ['ai_documents', 'uploaded_by', 'employees'],
        ['ai_knowledge_articles', 'created_by', 'employees'],
        ['ai_usage_logs', 'conversation_id', 'ai_conversations'],
        ['automation_escalations', 'recipient_employee_id', 'employees'],
        ['automation_escalations', 'recruiter_action_id', 'recruiter_actions'],
        ['automation_executions', 'automation_rule_version_id', 'automation_rule_versions'],
        ['automation_executions', 'candidate_application_id', 'candidate_applications'],
        ['automation_executions', 'parent_execution_id', 'automation_executions'],
        ['automation_executions', 'recruiter_id', 'employees'],
        ['candidate_applications', 'campaign_id', 'recruitment_campaigns'],
        ['candidate_applications', 'dropout_reason_id', 'recruitment_rejection_reasons'],
        ['candidate_applications', 'job_posting_id', 'job_postings'],
        ['candidate_applications', 'pipeline_stage_id', 'requisition_pipeline_stages'],
        ['candidate_applications', 'rejection_reason_id', 'recruitment_rejection_reasons'],
        ['candidate_communications', 'candidate_application_id', 'candidate_applications'],
        ['candidate_communications', 'communication_template_id', 'communication_templates'],
        ['candidate_communications', 'communication_template_version_id', 'communication_template_versions'],
        ['candidate_communications', 'interview_id', 'interviews'],
        ['candidate_communications', 'requisition_id', 'recruitment_requisitions'],
        ['candidate_communications', 'sent_by', 'employees'],
        ['candidate_documents', 'verified_by', 'employees'],
        ['candidate_duplicate_matches', 'resolved_by', 'employees'],
        ['candidate_joinings', 'created_by', 'employees'],
        ['candidate_joinings', 'dropout_reason_id', 'recruitment_rejection_reasons'],
        ['candidate_joinings', 'offer_id', 'offers'],
        ['candidate_portal_accounts', 'invited_by', 'employees'],
        ['candidate_stage_histories', 'changed_by', 'employees'],
        ['candidate_stage_histories', 'new_pipeline_stage_id', 'requisition_pipeline_stages'],
        ['candidate_stage_histories', 'previous_pipeline_stage_id', 'requisition_pipeline_stages'],
        ['candidate_timeline_events', 'candidate_application_id', 'candidate_applications'],
        ['candidate_timeline_events', 'candidate_joining_id', 'candidate_joinings'],
        ['candidate_timeline_events', 'interview_id', 'interviews'],
        ['candidate_timeline_events', 'offer_id', 'offers'],
        ['candidate_timeline_events', 'requisition_id', 'recruitment_requisitions'],
        ['candidates', 'created_by', 'employees'],
        ['candidates', 'referral_employee_id', 'employees'],
        ['communication_template_versions', 'created_by', 'employees'],
        ['communication_templates', 'created_by', 'employees'],
        ['communication_templates', 'updated_by', 'employees'],
        ['designations', 'department_id', 'departments'],
        ['employee_referrals', 'candidate_application_id', 'candidate_applications'],
        ['employee_referrals', 'created_by', 'employees'],
        ['employee_referrals', 'incentive_calculation_id', 'recruiter_incentive_calculations'],
        ['employee_referrals', 'rejection_reason_id', 'recruitment_rejection_reasons'],
        ['employee_referrals', 'requisition_id', 'recruitment_requisitions'],
        ['employee_referrals', 'reviewed_by', 'employees'],
        ['employee_referrals', 'source_id', 'candidate_sources'],
        ['employees', 'candidate_id', 'candidates'],
        ['employees', 'location_id', 'locations'],
        ['employees', 'reports_to_id', 'employees'],
        ['hiring_memory_records', 'candidate_application_id', 'candidate_applications'],
        ['hiring_memory_records', 'department_id', 'departments'],
        ['hiring_memory_records', 'designation_id', 'designations'],
        ['hiring_memory_records', 'requisition_id', 'recruitment_requisitions'],
        ['hiring_memory_records', 'supersedes_id', 'hiring_memory_records'],
        ['hiring_outcome_snapshots', 'candidate_application_id', 'candidate_applications'],
        ['hiring_outcome_snapshots', 'candidate_id', 'candidates'],
        ['hiring_outcome_snapshots', 'candidate_joining_id', 'candidate_joinings'],
        ['hiring_outcome_snapshots', 'department_id', 'departments'],
        ['hiring_outcome_snapshots', 'designation_id', 'designations'],
        ['hiring_outcome_snapshots', 'employee_id', 'employees'],
        ['hiring_outcome_snapshots', 'location_id', 'locations'],
        ['hiring_outcome_snapshots', 'requisition_id', 'recruitment_requisitions'],
        ['hiring_outcome_snapshots', 'role_dna_version_id', 'role_dna_versions'],
        ['hiring_outcome_snapshots', 'source_id', 'candidate_sources'],
        ['hiring_outcomes', 'candidate_application_id', 'candidate_applications'],
        ['hiring_outcomes', 'candidate_joining_id', 'candidate_joinings'],
        ['hiring_outcomes', 'employee_id', 'employees'],
        ['hiring_outcomes', 'hiring_outcome_snapshot_id', 'hiring_outcome_snapshots'],
        ['hiring_outcomes', 'offer_id', 'offers'],
        ['hiring_outcomes', 'requisition_id', 'recruitment_requisitions'],
        ['hiring_outcomes', 'supersedes_id', 'hiring_outcomes'],
        ['hiring_risks', 'candidate_application_id', 'candidate_applications'],
        ['hiring_risks', 'owner_id', 'employees'],
        ['hiring_risks', 'recruiter_action_id', 'recruiter_actions'],
        ['integration_statuses', 'last_tested_by', 'employees'],
        ['interview_availability_slots', 'cancelled_by', 'employees'],
        ['interview_availability_slots', 'created_by', 'employees'],
        ['interview_availability_slots', 'requisition_id', 'recruitment_requisitions'],
        ['interview_calendar_events', 'calendar_connection_id', 'calendar_connections'],
        ['interview_feedback', 'supersedes_id', 'interview_feedback'],
        ['interview_scheduling_invitations', 'created_by', 'employees'],
        ['interview_scheduling_invitations', 'interviewer_id', 'employees'],
        ['interview_slot_bookings', 'interview_id', 'interviews'],
        ['interview_slot_bookings', 'invitation_id', 'interview_scheduling_invitations'],
        ['interview_slot_bookings', 'rescheduled_from_id', 'interview_slot_bookings'],
        ['interviews', 'created_by', 'employees'],
        ['interviews', 'rejection_reason_id', 'recruitment_rejection_reasons'],
        ['job_postings', 'created_by', 'employees'],
        ['job_postings', 'updated_by', 'employees'],
        ['offer_letter_conversions', 'issued_by', 'employees'],
        ['offer_letter_template_versions', 'created_by', 'employees'],
        ['offer_letter_templates', 'created_by', 'employees'],
        ['offer_letters', 'issued_by', 'employees'],
        ['offer_revisions', 'designation_id', 'designations'],
        ['offer_revisions', 'location_id', 'locations'],
        ['offer_status_histories', 'changed_by', 'employees'],
        ['offers', 'created_by', 'employees'],
        ['offers', 'designation_id', 'designations'],
        ['offers', 'location_id', 'locations'],
        ['offers', 'offer_letter_template_id', 'offer_letter_templates'],
        ['outcome_insights', 'designation_id', 'designations'],
        ['outcome_insights', 'requisition_id', 'recruitment_requisitions'],
        ['ownership_handoffs', 'assignee_employee_id', 'employees'],
        ['ownership_handoffs', 'employee_id', 'employees'],
        ['recruiter_actions', 'automation_execution_id', 'automation_executions'],
        ['recruiter_actions', 'automation_rule_id', 'automation_rules'],
        ['recruiter_actions', 'candidate_application_id', 'candidate_applications'],
        ['recruiter_actions', 'candidate_id', 'candidates'],
        ['recruiter_actions', 'completed_by', 'employees'],
        ['recruiter_actions', 'created_by', 'employees'],
        ['recruiter_actions', 'owner_id', 'employees'],
        ['recruiter_actions', 'requisition_id', 'recruitment_requisitions'],
        ['recruiter_incentive_adjustments', 'created_by', 'employees'],
        ['recruiter_incentive_approvals', 'changed_by', 'employees'],
        ['recruiter_incentive_calculations', 'created_by', 'employees'],
        ['recruiter_incentive_calculations', 'employee_referral_id', 'employee_referrals'],
        ['recruiter_incentive_calculations', 'incentive_slab_id', 'recruitment_incentive_slabs'],
        ['recruiter_incentive_payments', 'paid_by', 'employees'],
        ['recruiter_performance_rules', 'created_by', 'employees'],
        ['recruitment_campaigns', 'created_by', 'employees'],
        ['recruitment_campaigns', 'owner_id', 'employees'],
        ['recruitment_costs', 'campaign_id', 'recruitment_campaigns'],
        ['recruitment_costs', 'created_by', 'employees'],
        ['recruitment_daily_activities', 'candidate_application_id', 'candidate_applications'],
        ['recruitment_daily_activities', 'candidate_id', 'candidates'],
        ['recruitment_daily_activities', 'created_by', 'employees'],
        ['recruitment_daily_targets', 'created_by', 'employees'],
        ['recruitment_followups', 'created_by', 'employees'],
        ['recruitment_incentive_rules', 'created_by', 'employees'],
        ['recruitment_manual_activities', 'created_by', 'employees'],
        ['recruitment_pipeline_template_versions', 'created_by', 'employees'],
        ['recruitment_pipeline_templates', 'cloned_from_id', 'recruitment_pipeline_templates'],
        ['recruitment_pipeline_templates', 'created_by', 'employees'],
        ['recruitment_requisition_approvals', 'changed_by', 'employees'],
        ['recruitment_requisitions', 'assistant_manager_id', 'employees'],
        ['recruitment_requisitions', 'created_by', 'employees'],
        ['recruitment_requisitions', 'hiring_manager_id', 'employees'],
        ['recruitment_requisitions', 'location_id', 'locations'],
        ['recruitment_requisitions', 'manager_id', 'employees'],
        ['recruitment_requisitions', 'pipeline_applied_by', 'employees'],
        ['recruitment_requisitions', 'pipeline_template_id', 'recruitment_pipeline_templates'],
        ['recruitment_requisitions', 'reporting_manager_id', 'employees'],
        ['recruitment_requisitions', 'vp_hr_id', 'employees'],
        ['recruitment_stages', 'created_by', 'employees'],
        ['rediscovery_runs', 'role_dna_version_id', 'role_dna_versions'],
        ['requisition_pipeline_stages', 'recruitment_stage_id', 'recruitment_stages'],
        ['role_dna_profiles', 'designation_id', 'designations'],
        ['talent_pool_memberships', 'added_by', 'employees'],
        ['talent_pool_memberships', 'removed_by', 'employees'],
        ['talent_pools', 'archived_by', 'employees'],
        ['talent_pools', 'created_by', 'employees'],
        ['talent_pools', 'department_id', 'departments'],
        ['talent_pools', 'owner_id', 'employees'],
        ['talent_signal_snapshots', 'candidate_application_id', 'candidate_applications'],
        ['talent_signal_snapshots', 'role_dna_version_id', 'role_dna_versions'],
    ];

    /**
     * Unique business identifiers and lookup keys that become unique per tenant:
     * [table, columns, current index name].
     *
     * @var list<array{0: string, 1: list<string>, 2: string}>
     */
    private const TENANT_UNIQUES = [
        ['ai_knowledge_articles', ['slug'], 'ai_knowledge_articles_slug_unique'],
        ['automation_executions', ['idempotency_key'], 'automation_executions_idempotency_key_unique'],
        ['automation_rules', ['key'], 'automation_rules_key_unique'],
        ['candidate_applications', ['application_code'], 'candidate_applications_application_code_unique'],
        ['candidate_communications', ['idempotency_key'], 'candidate_communications_idempotency_key_unique'],
        ['candidate_portal_accounts', ['email'], 'candidate_portal_accounts_email_unique'],
        ['candidate_sources', ['code'], 'candidate_sources_code_unique'],
        ['candidates', ['candidate_code'], 'candidates_candidate_code_unique'],
        ['code_sequences', ['key'], 'code_sequences_key_unique'],
        ['communication_templates', ['key', 'channel', 'language'], 'ct_key_channel_language_unique'],
        ['departments', ['code'], 'departments_code_unique'],
        ['designations', ['code'], 'designations_code_unique'],
        ['employee_referrals', ['referral_code'], 'employee_referrals_referral_code_unique'],
        ['employees', ['email'], 'employees_email_unique'],
        ['employees', ['employee_code'], 'employees_employee_code_unique'],
        ['hiring_memory_records', ['capture_key'], 'hiring_memory_records_capture_key_unique'],
        ['hiring_outcomes', ['dedupe_key', 'version'], 'hiring_outcomes_dedupe_version'],
        ['hiring_risks', ['open_key'], 'hiring_risks_open_key_unique'],
        ['integration_statuses', ['provider'], 'integration_statuses_provider_unique'],
        ['job_postings', ['public_slug'], 'job_postings_public_slug_unique'],
        ['locations', ['code'], 'locations_code_unique'],
        ['offers', ['offer_code'], 'offers_offer_code_unique'],
        ['outcome_insights', ['dedupe_key'], 'outcome_insights_dedupe_key_unique'],
        ['ownership_handoffs', ['dedupe_key'], 'ownership_handoffs_dedupe_key_unique'],
        ['recruiter_actions', ['dedupe_key'], 'recruiter_actions_dedupe_key_unique'],
        ['recruitment_campaigns', ['code'], 'recruitment_campaigns_code_unique'],
        ['recruitment_pipeline_templates', ['slug'], 'recruitment_pipeline_templates_slug_unique'],
        ['recruitment_rejection_reasons', ['code'], 'recruitment_rejection_reasons_code_unique'],
        ['recruitment_requisitions', ['code'], 'recruitment_requisitions_code_unique'],
        ['recruitment_settings', ['key'], 'recruitment_settings_key_unique'],
        ['recruitment_stages', ['code'], 'recruitment_stages_code_unique'],
        ['saved_table_views', ['user_id', 'resource', 'name'], 'saved_table_views_user_resource_name_unique'],
        ['talent_pools', ['slug'], 'talent_pools_slug_unique'],
    ];

    /**
     * SaaS-1 (validate + contract, step 4 of 4).
     *
     * Validate: refuses to run while any tenant-owned row has no tenant, or any reference (or role
     * assignment) points into another tenant — it reports table and column counts, never data.
     *
     * Contract: tenant_id becomes NOT NULL; business codes, slugs and lookup keys become unique per
     * tenant; references between tenant-owned rows become composite foreign keys
     * (tenant_id, x_id) → parent (tenant_id, id), so the database itself refuses a cross-tenant
     * link; roles and role assignments are keyed by tenant (spatie teams). MySQL foreign-key checks
     * are off only while the already-validated keys are added, so each ALTER stays in place.
     */
    public function up(): void
    {
        $this->validate();

        Schema::disableForeignKeyConstraints();

        try {
            $this->contractTenantColumns();
            $this->contractRoles();
            $this->contractUniques();
            $this->addParentKeys();
            $this->addCompositeReferences();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Restore the pre-release backup to roll back (P810-A4-01): composite keys and per-tenant
     * uniques cannot be safely reversed once a second tenant exists.
     */
    public function down(): void
    {
        //
    }

    private function validate(): void
    {
        $problems = [];

        foreach ([...self::TENANT_TABLES, 'roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            $missing = DB::table($table)->whereNull('tenant_id')->count();

            if ($missing > 0) {
                $problems[] = "{$table}: {$missing} rows without a tenant";
            }
        }

        foreach ([...self::COMPOSITE_REFERENCES, ...self::CHECKED_REFERENCES, ['model_has_roles', 'role_id', 'roles']] as $reference) {
            [$table, $column, $parent] = $reference;

            $crossing = DB::table($table.' as child')
                ->join($parent.' as parent', 'parent.id', '=', 'child.'.$column)
                ->whereColumn('parent.tenant_id', '!=', 'child.tenant_id')
                ->count();

            if ($crossing > 0) {
                $problems[] = "{$table}.{$column} → {$parent}: {$crossing} cross-tenant references";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException("Tenant ownership cannot be enforced yet:\n- ".implode("\n- ", $problems));
        }
    }

    private function contractTenantColumns(): void
    {
        foreach ([...self::TENANT_TABLES, 'roles'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });
        }
    }

    /**
     * Roles are tenant-owned through the team key; a role assignment's tenant must equal its
     * role's tenant (composite key below), and the tenant is part of each assignment's identity.
     */
    private function contractRoles(): void
    {
        Schema::table('roles', function (Blueprint $blueprint): void {
            $blueprint->dropUnique('roles_key_unique');
            $blueprint->dropUnique('roles_name_guard_name_unique');
            $blueprint->unique(['tenant_id', 'key'], 'roles_tenant_key_unique');
            $blueprint->unique(['tenant_id', 'name', 'guard_name'], 'roles_tenant_name_guard_unique');
            $blueprint->unique(['tenant_id', 'id'], 'roles_tenant_id_key');
        });

        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('model_has_roles', function (Blueprint $blueprint) use ($sqlite): void {
            $this->dropForeignOn($blueprint, 'model_has_roles', 'role_id', $sqlite);
            $blueprint->dropPrimary();
            $blueprint->unsignedBigInteger('tenant_id')->nullable(false)->change();
            $blueprint->primary(['tenant_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
            $blueprint->foreign(['tenant_id', 'role_id'], 'model_has_roles_role_tfk')->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
        });

        Schema::table('model_has_permissions', function (Blueprint $blueprint) use ($sqlite): void {
            $this->dropForeignOn($blueprint, 'model_has_permissions', 'permission_id', $sqlite);
            $blueprint->dropPrimary();
            $blueprint->unsignedBigInteger('tenant_id')->nullable(false)->change();
            $blueprint->primary(['tenant_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
            $blueprint->index('permission_id', 'model_has_permissions_permission_idx');
            $blueprint->foreign('permission_id', 'model_has_permissions_permission_fk')->references('id')->on('permissions')->cascadeOnDelete();
        });
    }

    private function contractUniques(): void
    {
        foreach (self::TENANT_UNIQUES as [$table, $columns, $index]) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $index): void {
                $blueprint->dropUnique($index);
                $blueprint->unique(['tenant_id', ...$columns], $index);
            });
        }
    }

    /**
     * A composite foreign key needs a unique (tenant_id, id) on the referenced table.
     */
    private function addParentKeys(): void
    {
        $parents = array_values(array_unique(array_column(self::COMPOSITE_REFERENCES, 2)));

        foreach ($parents as $parent) {
            Schema::table($parent, function (Blueprint $blueprint) use ($parent): void {
                $blueprint->unique(['tenant_id', 'id'], $parent.'_tenant_id_key');
            });
        }
    }

    private function addCompositeReferences(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        $byTable = [];

        foreach (self::COMPOSITE_REFERENCES as $reference) {
            $byTable[$reference[0]][] = $reference;
        }

        foreach ($byTable as $table => $references) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $references, $sqlite): void {
                foreach ($references as [, $column, $parent, $rule, $name]) {
                    $this->dropForeignOn($blueprint, $table, $column, $sqlite);
                    $blueprint->index(['tenant_id', $column], $name.'_tix');

                    $foreign = $blueprint->foreign(['tenant_id', $column], $name.'_tfk')->references(['tenant_id', 'id'])->on($parent);
                    $rule === 'cascade' ? $foreign->cascadeOnDelete() : $foreign->restrictOnDelete();
                }
            });
        }
    }

    /**
     * SQLite drops a foreign key by its columns (the table is rebuilt); MySQL needs the
     * constraint's actual name, read from the live schema rather than assumed.
     */
    private function dropForeignOn(Blueprint $blueprint, string $table, string $column, bool $sqlite): void
    {
        if ($sqlite) {
            $blueprint->dropForeign([$column]);

            return;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                $blueprint->dropForeign($foreignKey['name']);

                return;
            }
        }

        throw new RuntimeException("No foreign key on {$table}.{$column} to replace.");
    }
};
