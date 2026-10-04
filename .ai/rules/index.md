# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Services/AI/Providers/*.php | .ai/rules/a-i-providers.md |
| app/Services/InterviewService.php,app/Filament/Resources/Interviews/**,app/Filament/Pages/InterviewWorkspace.php,app/Services/AI/Tools/ActionTools/ScheduleInterviewTool.php | .ai/rules/action-tools.md |
| app/Services/AI/Actions/** | .ai/rules/actions.md |
| resources/views/components/**,resources/css/filament/admin/theme.css | .ai/rules/admin.md |
| app/Filament/** | .ai/rules/app-filament.md |
| app/Models/*.php, app/Models/CandidateStageHistory.php, app/Models/**, app/Models/User.php | .ai/rules/app-models.md |
| app/Policies/** | .ai/rules/app-policies.md |
| app/Services/TargetResolutionService.php,app/Models/RecruitmentDailyTarget.php, app/Services/CandidateTimelineService.php,app/Models/CandidateTimelineEvent.php | .ai/rules/app-services-models.md |
| app/Services/OfferService.php,app/Services/InterviewService.php, app/Services/ReferralService.php,app/Services/RecruiterIncentiveCalculator.php | .ai/rules/app-services-services.md |
| app/Services/RecruitmentAnalyticsService.php, app/Services/InterviewService.php, app/Services/RecruitmentSlaService.php, app/Services/OfferService.php, app/Services/StageTransitionService.php, app/Services/RecruitmentActivityService.php, app/Services/** | .ai/rules/app-services.md |
| app/Services/NotificationDispatchService.php,app/Services/Automation/**,app/Services/RecruiterActionService.php | .ai/rules/automation-services.md |
| app/Services/Automation/** | .ai/rules/automation.md |
| app/Services/CandidateJoiningService.php,app/Filament/Resources/CandidateJoinings/**,database/factories/CandidateJoiningFactory.php | .ai/rules/candidate-joinings-factories.md |
| app/Models/Candidate.php,app/Services/CandidateSearchTerm.php,app/Filament/Resources/Candidates/** | .ai/rules/candidates.md |
| docker-compose.yml,docker/**,app/Console/Commands/HeartbeatCheck.php,app/Services/WorkerHeartbeat.php,config/logging.php | .ai/rules/commands-services.md |
| routes/console.php,app/Console/Commands/** | .ai/rules/commands.md |
| app/Services/Communication/** | .ai/rules/communication.md |
| resources/css/filament/admin/theme.css,resources/views/filament/components/theme-*.blade.php,app/Providers/Filament/AdminPanelProvider.php | .ai/rules/components-providers-filament.md |
| app/Models/Concerns/Auditable.php,app/Models/*.php | .ai/rules/concerns-models.md |
| app/Models/AuditLog.php,app/Jobs/Concerns/**,app/Providers/AppServiceProvider.php | .ai/rules/concerns-providers.md |
| app/Models/AuditLog.php,app/Models/Concerns/Auditable.php | .ai/rules/concerns.md |
| tests/Concurrency/** | .ai/rules/concurrency.md |
| docker-compose.yml,docs/runbooks/queue-operations.md,app/Console/Commands/QueueDrainStatus.php,app/Services/QueueHealthService.php | .ai/rules/console-commands-services.md |
| app/Console/Commands/SweepStuckWork.php,config/communications.php | .ai/rules/console-commands.md |
| app/Http/Controllers/**,app/Providers/AppServiceProvider.php,config/filesystems.php | .ai/rules/controllers-providers.md |
| app/Providers/AppServiceProvider.php,app/Filament/Resources/**,resources/css/filament/admin/theme.css | .ai/rules/css-filament-admin.md |
| app/Models/RecruitmentSetting.php,app/Services/RecruitmentSettingService.php,app/Filament/Resources/RecruitmentSettings/**,app/Services/Metrics/Definitions/SlaLegCompliance.php | .ai/rules/definitions.md |
| app/Services/Distribution/** | .ai/rules/distribution.md |
| app/Services/ReferralService.php,app/Services/RecruiterIncentiveCalculator.php,app/Enums/IncentiveTriggerEvent.php, app/Services/PipelineTemplateService.php,app/Services/StageConfigurationService.php,app/Enums/StageHistoryEvent.php | .ai/rules/enums.md |
| app/Filament/**,app/Services/Export/**,app/Policies/ExportPolicy.php | .ai/rules/export-policies.md |
| database/seeders/**,database/factories/** | .ai/rules/factories.md |
| resources/css/filament/admin/theme.css | .ai/rules/filament-admin.md |
| app/Filament/Pages/*.php, app/Filament/Pages/Profile.php | .ai/rules/filament-pages.md |
| app/Filament/Widgets/** | .ai/rules/filament-widgets.md |
| app/Filament/Pages/Dashboard.php,app/Filament/Widgets/**,app/Providers/Filament/AdminPanelProvider.php | .ai/rules/filament.md |
| app/Services/AI/Gateway/*.php | .ai/rules/gateway.md |
| composer.json, docker-compose.yml | .ai/rules/general.md |
| app/Services/Governance/**,config/outcomes.php,config/metrics.php | .ai/rules/governance.md |
| app/Models/User.php,app/Filament/**,app/Services/Identity/**,app/Console/Commands/** | .ai/rules/identity-console-commands.md |
| app/Services/Identity/** | .ai/rules/identity.md |
| app/Filament/Resources/Candidates/Schemas/CandidatePicker.php,app/Filament/Resources/CandidateApplications/**,app/Filament/Resources/Candidates/RelationManagers/**,app/Services/Intelligence/TalentRediscoveryService.php,app/Services/RecruitmentActivityService.php | .ai/rules/intelligence-services.md |
| app/Services/AI/Tools/IntelligenceTools/** | .ai/rules/intelligence-tools.md |
| app/Services/Intelligence/** | .ai/rules/intelligence.md |
| app/Jobs/**,app/Console/Commands/**,routes/console.php | .ai/rules/jobs-console-commands.md |
| app/Jobs/** | .ai/rules/jobs.md |
| app/Services/OfferService.php,app/Services/InterviewService.php,app/Services/CandidateJoiningService.php,app/Events/OfferAccepted.php,app/Listeners/CreateJoiningRecordForAcceptedOffer.php | .ai/rules/listeners.md |
| app/Jobs/**,app/Listeners/**,app/Notifications/**,app/Mail/** | .ai/rules/mail.md |
| app/Services/Metrics/**,app/Services/RecruitmentAnalyticsService.php | .ai/rules/metrics-services.md |
| app/Services/Metrics/** | .ai/rules/metrics.md |
| routes/portal.php,app/Http/Controllers/Portal/**,app/Http/Middleware/*Candidate*.php,app/Services/CandidateStepUpService.php | .ai/rules/middleware-services.md |
| database/migrations/** | .ai/rules/migrations.md |
| app/Services/StageTransitionService.php,app/Services/PipelineTemplateService.php,app/Services/StageConfigurationService.php,app/Models/RecruitmentStage.php,app/Models/RequisitionPipelineStage.php,app/Models/CandidateApplication.php | .ai/rules/models-models-models.md |
| app/Models/Department.php,app/Models/Designation.php,app/Models/Location.php,app/Models/CandidateSource.php,app/Models/RecruitmentRejectionReason.php,app/Services/MasterDataLifecycleService.php | .ai/rules/models-models-services.md |
| app/Services/StageTransitionService.php,app/Services/RequisitionApprovalService.php,app/Models/CandidateApplication.php,app/Models/RecruitmentRequisition.php | .ai/rules/models-models.md |
| app/Models/CandidateJoining.php,app/Services/EmployeeConversionService.php, app/Models/*.php,app/Services/*.php | .ai/rules/models-services.md |
| app/Services/HierarchyService.php,app/Observers/EmployeeObserver.php,app/Models/Employee.php | .ai/rules/models.md |
| docker-compose.yml,config/queue.php,app/Services/QueueHealthService.php,app/Jobs/**,app/Notifications/**,app/Mail/** | .ai/rules/notifications-mail.md |
| app/Services/HierarchyService.php,app/Services/HierarchyMemo.php,app/Observers/EmployeeObserver.php,app/Providers/AppServiceProvider.php | .ai/rules/observers-providers.md |
| app/Services/OfferLetterRenderer.php,app/Services/WordToPdfConverter.php,app/Models/OfferLetterTemplate.php,app/Filament/Resources/OfferLetterTemplates/** | .ai/rules/offer-letter-templates.md |
| app/Services/OfferService.php,app/Filament/Resources/Offers/**,app/Models/OfferStatusHistory.php | .ai/rules/offers-models.md |
| app/Services/OfferService.php,app/Services/OfferLetterIssuanceService.php,app/Models/OfferLetterTemplate.php,app/Filament/Resources/Offers/** | .ai/rules/offers.md |
| app/Services/Outcomes/**, app/Services/Outcomes/OutcomeLearningService.php | .ai/rules/outcomes.md |
| app/Services/AiAssistantService.php,app/Filament/Pages/AiCopilot.php | .ai/rules/pages.md |
| app/Services/OfferLetterRenderer.php,app/Models/OfferLetterTemplate.php,app/Filament/Resources/OfferLetterTemplates/**,app/Filament/Resources/Offers/**,resources/views/pdf/offer-letter*.blade.php | .ai/rules/pdf.md |
| app/Filament/Resources/RecruitmentRequisitions/**,app/Filament/Resources/Candidates/**,app/Filament/Resources/CandidateApplications/**,app/Policies/RecruitmentRequisitionPolicy.php,app/Policies/CandidatePolicy.php | .ai/rules/policies-policies.md |
| app/Filament/Resources/**,app/Policies/** | .ai/rules/policies.md |
| app/Models/AuditLog.php,app/Services/CandidatePortalService.php,app/Http/Controllers/Portal/**,routes/portal.php | .ai/rules/portal.md |
| app/Filament/Widgets/**,resources/views/filament/**,app/Providers/Filament/AdminPanelProvider.php | .ai/rules/providers-filament.md |
| app/Policies/**,app/Providers/AppServiceProvider.php,app/Providers/Filament/AdminPanelProvider.php | .ai/rules/providers-providers-filament.md |
| app/Providers/AppServiceProvider.php,bootstrap/app.php,config/app.php,app/Services/CandidatePortalService.php | .ai/rules/providers-services.md |
| app/Services/AI/Providers/*.php,app/Services/AI/Gateway/*.php,app/Providers/AiServiceProvider.php | .ai/rules/providers.md |
| app/Services/RecruiterIncentiveCalculator.php,app/Filament/Resources/RecruiterIncentiveCalculations/** | .ai/rules/recruiter-incentive-calculations.md |
| app/Models/RecruitmentDailyTarget.php,app/Services/RecruitmentTargetService.php,app/Filament/Resources/RecruitmentDailyTargets/**,app/Policies/RecruitmentDailyTargetPolicy.php | .ai/rules/recruitment-daily-targets-policies.md |
| app/Services/RecruiterIncentiveCalculator.php,app/Models/RecruitmentIncentiveRule.php,app/Models/RecruitmentIncentiveSlab.php,app/Filament/Resources/RecruitmentIncentiveRules/** | .ai/rules/recruitment-incentive-rules.md |
| app/Filament/Resources/**,app/Filament/Pages/*.php | .ai/rules/resources-filament-pages.md |
| app/Services/OfferLetterIssuanceService.php,app/Jobs/ConvertOfferLetterJob.php,app/Models/OfferLetterConversion.php,app/Filament/Resources/Offers/** | .ai/rules/resources-offers.md |
| routes/** | .ai/rules/routes.md |
| app/Filament/Resources/Interviews/Schemas/InterviewForm.php,app/Models/Interviewer.php,app/Services/InterviewerImportService.php | .ai/rules/schemas-models-services.md |
| app/Filament/Resources/**/Schemas/*.php | .ai/rules/schemas.md |
| app/Services/Automation/AutomationRuleService.php,app/Services/Communication/CommunicationTemplateService.php | .ai/rules/services-communication.md |
| app/Services/RecruiterIncentiveCalculator.php,app/Models/RecruitmentIncentiveRule.php,app/Models/RecruitmentIncentiveSlab.php,app/Models/RecruiterIncentiveCalculation.php | .ai/rules/services-models-models-models.md |
| app/Services/RecruiterIncentiveCalculator.php,app/Services/IncentiveApprovalService.php,app/Models/RecruiterIncentiveCalculation.php, app/Services/IncentiveApprovalService.php,app/Services/RecruiterIncentiveCalculator.php,app/Models/RecruitmentIncentiveSlab.php, app/Services/RequisitionApprovalService.php,app/Services/StageTransitionService.php,app/Models/RecruitmentRejectionReason.php | .ai/rules/services-models.md |
| app/Services/IncentiveApprovalService.php,app/Policies/RecruiterIncentiveCalculationPolicy.php | .ai/rules/services-policies.md |
| app/Filament/Resources/Interviews/Schemas/InterviewForm.php,app/Models/Interviewer.php,app/Services/InterviewerImportService.php,app/Services/InterviewService.php | .ai/rules/services-services.md |
| app/Services/RecruiterDailyMetricsService.php,app/Models/RecruitmentManualActivity.php,app/Models/RecruitmentDailyActivity.php,app/Services/TargetResolutionService.php | .ai/rules/services.md |
| app/Services/TalentPoolService.php,app/Filament/Resources/TalentPools/**,app/Filament/Resources/Candidates/Tables/CandidatesTable.php | .ai/rules/tables.md |
| tests/**,app/Providers/AppServiceProvider.php | .ai/rules/tests-providers.md |
| tests/** | .ai/rules/tests.md |
| app/Services/AI/Tools/** | .ai/rules/tools.md |
| app/Filament/Pages/AiCopilot.php,app/Services/AI/Actions/**,app/Services/AI/Tools/ActionTools/**,resources/views/filament/pages/ai-copilot.blade.php | .ai/rules/views-filament-pages.md |
| resources/views/filament/** | .ai/rules/views-filament.md |
| app/Http/Controllers/Webhooks/** | .ai/rules/webhooks.md |
| app/Filament/Widgets/**,app/Filament/Pages/Dashboard.php | .ai/rules/widgets-filament-pages.md |
| app/Services/RecruiterIncentiveCalculator.php,app/Services/IncentiveStatementService.php,app/Models/RecruiterIncentiveCalculation.php,app/Filament/Pages/IncentiveDashboard.php,app/Filament/Widgets/IncentiveDashboardStats.php | .ai/rules/widgets.md |
