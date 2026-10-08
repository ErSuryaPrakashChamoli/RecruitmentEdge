# Phase 7 Commit Plan

Prepared 2026-09-26. **Nothing has been committed.** This plan is for review; run it only after approval. Do not push until the series has been reviewed.

## 1. Commit order

| # | Group | Message | Files |
|---|---|---|---|
| 1 | A | `phase-4: configurable recruitment foundation` | 202 |
| 2 | B | `phase-4.1: recruitment hardening` | 12 |
| 3 | C | `phase-5: communication and distribution` | 173 |
| 4 | D | `phase-6: automation and action os` | 113 |
| 5 | E | `phase-7: edge intelligence` | 91 |
| 6 | F | `phase-7: provider failure handling` | 7 |
| 7 | G | `phase-7: final hardening and documentation` | 9 |
| 8 | H | `phases 4-7: shared integration points` | 55 |
| | | **Total** | **662** |

## 2. Dependencies

```
HEAD 9cba8e3
 └─ 1 phase-4 (A)
     ├─ 2 phase-4.1 (B)
     └─ 3 phase-5 (C)
         └─ 4 phase-6 (D)          uses communication actions/events from 5
             └─ 5 phase-7 (E)      uses automation events, Action Center, NBA from 6
                 └─ 6 provider failure handling (F)   IntelligenceAiService + job from 7
                     └─ 7 hardening & docs (G)
                         └─ 8 shared integration points (H)   hunks from 4, 4.1, 5, 6 and 7
```

## 3. Shared files and why they go last

The 55 files in group H were edited in more than one major phase (per-file reasons in `docs/phase-7-freeze-and-commit-review.md` §4). The options were:

- **Split** each file per phase: needs interactive hunk staging, and many hunks cannot stand alone (for example `RolePermissionSeeder` constants for 6 and 7 sit in one array). Not safe to do non-interactively.
- **Put each in its latest phase**: phase 4 code would then reference methods that only appear in a phase-7 commit.
- **Keep them together in a final shared commit** (chosen): the last commit is exactly the tree that passed the full quality gate.

## 4. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Commits 1–7 are not individually bootable: they reference wiring, permissions and model methods that arrive in commit 8. | `git bisect` or checking out a middle commit gives a broken app. | Treat the eight commits as one reviewable unit. Merge the branch as a whole (merge commit or squash), never deploy or cherry-pick a middle commit. Only the tip is tested. |
| Paths staged into the wrong commit. | Mis-attributed history only; the tip is unchanged. | After staging, `git status --short` must show nothing unexpected; after commit 8, `git status --short` must be empty. |
| New files added after this plan was written. | Left untracked. | Check `git status --short` before and after; the final commit must leave a clean tree. |
| Gemini key rotation outstanding. | Not a commit risk: the key is not in any file being committed. | Rotate before production (see readiness checklist). |

## 5. Pre-flight

```bash
git status --short -uall | wc -l        # expect 662 entries (82 modified + 580 untracked)
git diff --check                        # expect no output
git ls-files .env                       # expect no output
```

## 6. Staging commands

Each block stages one commit with an explicit path list, then commits. `--pathspec-from-file=-` reads the paths from the heredoc.

### Commit 1 — group A: `phase-4: configurable recruitment foundation` (202 files)

```bash
git add --pathspec-from-file=- <<'PATHS'
.ai/rules/app-services-models.md
.ai/rules/enums.md
.ai/rules/migrations.md
.ai/rules/models-models-models.md
.ai/rules/portal.md
.ai/rules/tests.md
app/Console/Commands/AssignDefaultPipelines.php
app/Console/Commands/ExpireLapsedInterviewSlots.php
app/Enums/DuplicateMatchType.php
app/Enums/IncentiveTriggerEvent.php
app/Enums/InterviewSlotStatus.php
app/Enums/ReferralIncentiveStatus.php
app/Enums/ReferralRelationship.php
app/Enums/ReferralStatus.php
app/Enums/SchedulingChannel.php
app/Enums/SlotBookingStatus.php
app/Enums/StageRequirement.php
app/Enums/StageType.php
app/Enums/TalentPoolMemberSource.php
app/Enums/TalentPoolStatus.php
app/Enums/TalentPoolVisibility.php
app/Enums/TimelineEventType.php
app/Enums/TimelineSource.php
app/Enums/TimelineVisibility.php
app/Events/CandidateAddedToTalentPool.php
app/Events/CandidatePortalDocumentUploaded.php
app/Events/CandidatePortalProfileUpdated.php
app/Events/CandidateRemovedFromTalentPool.php
app/Events/CandidateRescheduled.php
app/Events/CandidateStageChanged.php
app/Events/DuplicateCandidateDetected.php
app/Events/DuplicateOverrideApproved.php
app/Events/InterviewSlotBooked.php
app/Events/InterviewSlotCancelled.php
app/Events/ReferralStatusChanged.php
app/Events/ReferralSubmitted.php
app/Filament/Resources/CandidateApplications/Tables/CandidateApplicationsTable.php
app/Filament/Resources/CandidatePortalAccounts/CandidatePortalAccountResource.php
app/Filament/Resources/CandidatePortalAccounts/Pages/ListCandidatePortalAccounts.php
app/Filament/Resources/CandidatePortalAccounts/Pages/ViewCandidatePortalAccount.php
app/Filament/Resources/CandidatePortalAccounts/Tables/CandidatePortalAccountsTable.php
app/Filament/Resources/Candidates/Pages/CreateCandidate.php
app/Filament/Resources/Candidates/RelationManagers/TalentPoolsRelationManager.php
app/Filament/Resources/Candidates/Schemas/CandidateForm.php
app/Filament/Resources/Candidates/Tables/CandidatesTable.php
app/Filament/Resources/EmployeeReferrals/EmployeeReferralResource.php
app/Filament/Resources/EmployeeReferrals/Pages/CreateEmployeeReferral.php
app/Filament/Resources/EmployeeReferrals/Pages/ListEmployeeReferrals.php
app/Filament/Resources/EmployeeReferrals/Pages/ViewEmployeeReferral.php
app/Filament/Resources/EmployeeReferrals/Schemas/EmployeeReferralInfolist.php
app/Filament/Resources/EmployeeReferrals/Tables/EmployeeReferralsTable.php
app/Filament/Resources/EmployeeReferrals/Widgets/ReferralLeaderboard.php
app/Filament/Resources/EmployeeReferrals/Widgets/ReferralStats.php
app/Filament/Resources/InterviewAvailabilitySlots/InterviewAvailabilitySlotResource.php
app/Filament/Resources/InterviewAvailabilitySlots/Pages/CreateInterviewAvailabilitySlot.php
app/Filament/Resources/InterviewAvailabilitySlots/Pages/ListInterviewAvailabilitySlots.php
app/Filament/Resources/InterviewAvailabilitySlots/Pages/ViewInterviewAvailabilitySlot.php
app/Filament/Resources/InterviewAvailabilitySlots/RelationManagers/BookingsRelationManager.php
app/Filament/Resources/InterviewAvailabilitySlots/Schemas/InterviewAvailabilitySlotForm.php
app/Filament/Resources/InterviewAvailabilitySlots/Schemas/InterviewAvailabilitySlotInfolist.php
app/Filament/Resources/InterviewAvailabilitySlots/Tables/InterviewAvailabilitySlotsTable.php
app/Filament/Resources/InterviewAvailabilitySlots/Widgets/SelfSchedulingStats.php
app/Filament/Resources/RecruiterIncentiveCalculations/Pages/ListRecruiterIncentiveCalculations.php
app/Filament/Resources/RecruitmentPipelineTemplates/Pages/CreateRecruitmentPipelineTemplate.php
app/Filament/Resources/RecruitmentPipelineTemplates/Pages/EditRecruitmentPipelineTemplate.php
app/Filament/Resources/RecruitmentPipelineTemplates/Pages/ListRecruitmentPipelineTemplates.php
app/Filament/Resources/RecruitmentPipelineTemplates/Pages/ViewRecruitmentPipelineTemplate.php
app/Filament/Resources/RecruitmentPipelineTemplates/RecruitmentPipelineTemplateResource.php
app/Filament/Resources/RecruitmentPipelineTemplates/Schemas/RecruitmentPipelineTemplateForm.php
app/Filament/Resources/RecruitmentPipelineTemplates/Schemas/RecruitmentPipelineTemplateInfolist.php
app/Filament/Resources/RecruitmentPipelineTemplates/Tables/RecruitmentPipelineTemplatesTable.php
app/Filament/Resources/RecruitmentRequisitions/Pages/CreateRecruitmentRequisition.php
app/Filament/Resources/RecruitmentRequisitions/RelationManagers/PipelineStagesRelationManager.php
app/Filament/Resources/RecruitmentRequisitions/Schemas/RecruitmentRequisitionForm.php
app/Filament/Resources/RecruitmentSettings/Pages/ManageRecruitmentConfiguration.php
app/Filament/Resources/RecruitmentStages/Pages/CreateRecruitmentStage.php
app/Filament/Resources/RecruitmentStages/Pages/EditRecruitmentStage.php
app/Filament/Resources/RecruitmentStages/Pages/ListRecruitmentStages.php
app/Filament/Resources/RecruitmentStages/Pages/ViewRecruitmentStage.php
app/Filament/Resources/RecruitmentStages/RecruitmentStageResource.php
app/Filament/Resources/RecruitmentStages/Schemas/RecruitmentStageForm.php
app/Filament/Resources/RecruitmentStages/Schemas/RecruitmentStageInfolist.php
app/Filament/Resources/RecruitmentStages/Tables/RecruitmentStagesTable.php
app/Filament/Resources/TalentPools/Pages/CreateTalentPool.php
app/Filament/Resources/TalentPools/Pages/EditTalentPool.php
app/Filament/Resources/TalentPools/Pages/ListTalentPools.php
app/Filament/Resources/TalentPools/Pages/ViewTalentPool.php
app/Filament/Resources/TalentPools/RelationManagers/MembersRelationManager.php
app/Filament/Resources/TalentPools/Schemas/TalentPoolForm.php
app/Filament/Resources/TalentPools/Schemas/TalentPoolInfolist.php
app/Filament/Resources/TalentPools/Tables/TalentPoolsTable.php
app/Filament/Resources/TalentPools/TalentPoolResource.php
app/Filament/Resources/TalentPools/Widgets/TalentPoolStats.php
app/Http/Controllers/Portal/ApplicationController.php
app/Http/Controllers/Portal/AuthController.php
app/Http/Controllers/Portal/DocumentController.php
app/Http/Controllers/Portal/PasswordController.php
app/Http/Controllers/Portal/ProfileController.php
app/Http/Controllers/Portal/SchedulingController.php
app/Http/Middleware/EnsureCandidatePortalAccountIsActive.php
app/Http/Requests/Portal/BookSlotRequest.php
app/Http/Requests/Portal/LoginRequest.php
app/Http/Requests/Portal/RescheduleRequestRequest.php
app/Http/Requests/Portal/SetPasswordRequest.php
app/Http/Requests/Portal/UploadDocumentRequest.php
app/Listeners/NotifyRecruitersOfPortalDocument.php
app/Listeners/NotifyReviewersOfReferral.php
app/Listeners/RecordDuplicateDecisionsOnTimeline.php
app/Listeners/SyncReferralsWithApplication.php
app/Mail/CandidatePortalLink.php
app/Models/AuditLog.php
app/Models/CandidateStageHistory.php
app/Models/CandidateTimelineEvent.php
app/Models/Concerns/DescribesPipelineStage.php
app/Models/Employee.php
app/Models/EmployeeReferral.php
app/Models/InterviewAvailabilitySlot.php
app/Models/InterviewSchedulingInvitation.php
app/Models/InterviewSlotBooking.php
app/Models/RecruitmentPipelineTemplate.php
app/Models/RecruitmentPipelineTemplateStage.php
app/Models/RecruitmentSetting.php
app/Models/RecruitmentStage.php
app/Models/RecruitmentStageTransition.php
app/Models/RequisitionPipelineStage.php
app/Models/TalentPool.php
app/Models/TalentPoolMembership.php
app/Observers/CandidateObserver.php
app/Policies/CandidatePortalAccountPolicy.php
app/Policies/EmployeeReferralPolicy.php
app/Policies/InterviewAvailabilitySlotPolicy.php
app/Policies/RecruitmentPipelineTemplatePolicy.php
app/Policies/RecruitmentStagePolicy.php
app/Policies/TalentPoolPolicy.php
app/Services/AI/Tools/CandidateTools/FindDuplicateCandidatesTool.php
app/Services/AI/Tools/CandidateTools/GetCandidateTimelineTool.php
app/Services/AI/Tools/CandidateTools/ListTalentPoolsTool.php
app/Services/AI/Tools/JobTools/GetRequisitionPipelineTool.php
app/Services/CandidateDuplicateDetector.php
app/Services/CandidateIdentityNormalizer.php
app/Services/CandidateTimelineService.php
app/Services/DuplicateCandidateFoundException.php
app/Services/DuplicateCandidateMatch.php
app/Services/PipelineTemplateService.php
app/Services/StageConfigurationService.php
app/Services/StageTransitionService.php
app/Services/TalentPoolService.php
config/auth.php
database/factories/CandidatePortalAccountFactory.php
database/factories/EmployeeReferralFactory.php
database/factories/InterviewAvailabilitySlotFactory.php
database/factories/InterviewSchedulingInvitationFactory.php
database/factories/InterviewSlotBookingFactory.php
database/factories/RecruitmentPipelineTemplateFactory.php
database/factories/RecruitmentStageFactory.php
database/factories/TalentPoolFactory.php
database/factories/TalentPoolMembershipFactory.php
database/migrations/2026_09_25_134635_create_recruitment_stages_table.php
database/migrations/2026_09_25_134636_create_recruitment_stage_transitions_table.php
database/migrations/2026_09_25_134637_create_recruitment_pipeline_templates_table.php
database/migrations/2026_09_25_134638_create_recruitment_pipeline_template_stages_table.php
database/migrations/2026_09_25_134639_create_requisition_pipeline_stages_table.php
database/migrations/2026_09_25_134640_add_pipeline_columns_to_recruitment_tables.php
database/migrations/2026_09_25_135221_grant_phase_four_permissions.php
database/migrations/2026_09_25_140258_add_normalized_identity_columns_to_candidates_table.php
database/migrations/2026_09_25_140610_create_candidate_timeline_events_table.php
database/migrations/2026_09_25_140932_create_talent_pools_table.php
database/migrations/2026_09_25_140933_create_talent_pool_memberships_table.php
database/migrations/2026_09_25_141540_create_employee_referrals_table.php
database/migrations/2026_09_25_142217_create_interview_availability_slots_table.php
database/migrations/2026_09_25_142218_create_interview_scheduling_invitations_table.php
database/migrations/2026_09_25_142219_create_interview_slot_bookings_table.php
database/migrations/2026_09_25_142808_create_candidate_portal_accounts_table.php
database/migrations/2026_09_25_143545_add_actor_to_audit_logs_table.php
resources/views/components/portal/card.blade.php
resources/views/components/recruitment/timeline.blade.php
resources/views/filament/resources/candidate-applications/view.blade.php
resources/views/filament/resources/candidates/view.blade.php
resources/views/mail/candidate-portal-link.blade.php
resources/views/portal/applications/show.blade.php
resources/views/portal/auth/forgot-password.blade.php
resources/views/portal/auth/login.blade.php
resources/views/portal/auth/set-password.blade.php
resources/views/portal/documents.blade.php
resources/views/portal/partials/input-class.blade.php
resources/views/portal/schedule/booking.blade.php
resources/views/portal/schedule/show.blade.php
resources/views/portal/schedule/slot-list.blade.php
routes/portal.php
tests/Feature/Ai/PhaseFourAiIntegrationTest.php
tests/Feature/AssignDefaultPipelinesCommandTest.php
tests/Feature/CandidateTimelineTest.php
tests/Feature/ConfiguredPipelineTransitionTest.php
tests/Feature/DuplicateCandidateDetectorTest.php
tests/Feature/EmployeeReferralTest.php
tests/Feature/InterviewSlotsUiTest.php
tests/Feature/PipelineConfigurationUiTest.php
tests/Feature/PipelineTemplateServiceTest.php
tests/Feature/RecruitmentSlaServiceTest.php
tests/Feature/RolePermissionSeederTest.php
tests/Feature/StageBuilderTest.php
tests/Feature/TalentPoolTest.php
PATHS
git diff --cached --stat | tail -1
git commit -m 'phase-4: configurable recruitment foundation' -m 'Configurable pipelines and stages, duplicate detection, unified timeline, talent pools, referrals, interview self-scheduling, candidate portal.' -m 'Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>'
```

### Commit 2 — group B: `phase-4.1: recruitment hardening` (12 files)

```bash
git add --pathspec-from-file=- <<'PATHS'
.ai/rules/widgets.md
app/Enums/IncentiveBeneficiary.php
app/Filament/Pages/IncentiveDashboard.php
app/Filament/Resources/RecruiterIncentiveCalculations/Tables/RecruiterIncentiveCalculationsTable.php
app/Filament/Widgets/IncentiveDashboardStats.php
app/Models/RecruiterIncentiveCalculation.php
app/Services/IncentiveStatementService.php
database/migrations/2026_09_25_204142_add_beneficiary_to_recruiter_incentive_calculations_table.php
resources/views/filament/pages/pipeline.blade.php
resources/views/pdf/incentive-statement-period.blade.php
tests/Feature/ConfiguredPipelineBoardTest.php
tests/Feature/ReferralIncentiveBeneficiaryTest.php
PATHS
git diff --cached --stat | tail -1
git commit -m 'phase-4.1: recruitment hardening' -m 'Referral incentive beneficiary, configured pipeline board, incentive statement fixes.' -m 'Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>'
```

### Commit 3 — group C: `phase-5: communication and distribution` (173 files)

```bash
git add --pathspec-from-file=- <<'PATHS'
.ai/rules/communication.md
.ai/rules/distribution.md
.ai/rules/webhooks.md
.env.example
app/Console/Commands/SyncJobDistributions.php
app/Enums/CampaignStatus.php
app/Enums/CommunicationChannel.php
app/Enums/CommunicationStatus.php
app/Enums/DistributionStatus.php
app/Enums/JobPostingStatus.php
app/Enums/MeetingProvider.php
app/Enums/PreferenceStatus.php
app/Enums/TemplateStatus.php
app/Events/CandidateAppliedOnline.php
app/Events/InterviewCancelled.php
app/Events/InterviewRescheduled.php
app/Events/InterviewScheduled.php
app/Events/OfferReleased.php
app/Filament/Pages/Integrations.php
app/Filament/Resources/CalendarConnections/CalendarConnectionResource.php
app/Filament/Resources/CalendarConnections/Pages/ListCalendarConnections.php
app/Filament/Resources/CalendarConnections/Tables/CalendarConnectionsTable.php
app/Filament/Resources/CandidateCommunicationPreferences/Actions/EditPreferencesAction.php
app/Filament/Resources/CandidateCommunicationPreferences/CandidateCommunicationPreferenceResource.php
app/Filament/Resources/CandidateCommunicationPreferences/Pages/ListCandidateCommunicationPreferences.php
app/Filament/Resources/CandidateCommunicationPreferences/Tables/CandidateCommunicationPreferencesTable.php
app/Filament/Resources/CandidateCommunications/Actions/SendMessageAction.php
app/Filament/Resources/CandidateCommunications/CandidateCommunicationResource.php
app/Filament/Resources/CandidateCommunications/Pages/ListCandidateCommunications.php
app/Filament/Resources/CandidateCommunications/Pages/ViewCandidateCommunication.php
app/Filament/Resources/CandidateCommunications/Schemas/CandidateCommunicationInfolist.php
app/Filament/Resources/CandidateCommunications/Tables/CandidateCommunicationsTable.php
app/Filament/Resources/CandidateCommunications/Widgets/CommunicationStats.php
app/Filament/Resources/CommunicationTemplates/CommunicationTemplateResource.php
app/Filament/Resources/CommunicationTemplates/Pages/CreateCommunicationTemplate.php
app/Filament/Resources/CommunicationTemplates/Pages/EditCommunicationTemplate.php
app/Filament/Resources/CommunicationTemplates/Pages/ListCommunicationTemplates.php
app/Filament/Resources/CommunicationTemplates/Pages/ViewCommunicationTemplate.php
app/Filament/Resources/CommunicationTemplates/Schemas/CommunicationTemplateForm.php
app/Filament/Resources/CommunicationTemplates/Schemas/CommunicationTemplateInfolist.php
app/Filament/Resources/CommunicationTemplates/Tables/CommunicationTemplatesTable.php
app/Filament/Resources/Interviews/Schemas/InterviewForm.php
app/Filament/Resources/JobPostings/Actions/PublishingActions.php
app/Filament/Resources/JobPostings/JobPostingResource.php
app/Filament/Resources/JobPostings/Pages/CreateJobPosting.php
app/Filament/Resources/JobPostings/Pages/EditJobPosting.php
app/Filament/Resources/JobPostings/Pages/ListJobPostings.php
app/Filament/Resources/JobPostings/Pages/ViewJobPosting.php
app/Filament/Resources/JobPostings/RelationManagers/DistributionsRelationManager.php
app/Filament/Resources/JobPostings/Schemas/JobPostingForm.php
app/Filament/Resources/JobPostings/Schemas/JobPostingInfolist.php
app/Filament/Resources/JobPostings/Tables/JobPostingsTable.php
app/Filament/Resources/JobPostings/Widgets/DistributionStats.php
app/Filament/Resources/RecruitmentCampaigns/Pages/CreateRecruitmentCampaign.php
app/Filament/Resources/RecruitmentCampaigns/Pages/EditRecruitmentCampaign.php
app/Filament/Resources/RecruitmentCampaigns/Pages/ListRecruitmentCampaigns.php
app/Filament/Resources/RecruitmentCampaigns/Pages/ViewRecruitmentCampaign.php
app/Filament/Resources/RecruitmentCampaigns/RecruitmentCampaignResource.php
app/Filament/Resources/RecruitmentCampaigns/Schemas/RecruitmentCampaignForm.php
app/Filament/Resources/RecruitmentCampaigns/Schemas/RecruitmentCampaignInfolist.php
app/Filament/Resources/RecruitmentCampaigns/Tables/RecruitmentCampaignsTable.php
app/Filament/Resources/RecruitmentCampaigns/Widgets/CampaignMetrics.php
app/Filament/Resources/RecruitmentCosts/Schemas/RecruitmentCostForm.php
app/Http/Controllers/Careers/CareerSiteController.php
app/Http/Controllers/Integrations/CalendarOAuthController.php
app/Http/Controllers/Webhooks/CommunicationWebhookController.php
app/Http/Requests/Careers/ApplyRequest.php
app/Jobs/PublishJobDistributionJob.php
app/Jobs/SyncInterviewCalendarJob.php
app/Listeners/SyncInterviewCalendar.php
app/Mail/CandidateMessageMail.php
app/Models/CalendarConnection.php
app/Models/CandidateCommunication.php
app/Models/CandidateCommunicationPreference.php
app/Models/CommunicationTemplate.php
app/Models/CommunicationTemplateVersion.php
app/Models/CommunicationWebhookEvent.php
app/Models/IntegrationStatus.php
app/Models/Interview.php
app/Models/InterviewCalendarEvent.php
app/Models/JobDistribution.php
app/Models/JobPosting.php
app/Models/RecruitmentCampaign.php
app/Models/RecruitmentCost.php
app/Policies/CalendarConnectionPolicy.php
app/Policies/CandidateCommunicationPolicy.php
app/Policies/CandidateCommunicationPreferencePolicy.php
app/Policies/CommunicationTemplatePolicy.php
app/Policies/JobPostingPolicy.php
app/Policies/RecruitmentCampaignPolicy.php
app/Services/AI/Tools/ActionTools/ScheduleInterviewTool.php
app/Services/AI/Tools/ActionTools/SendCandidateEmailTool.php
app/Services/Communication/CommunicationPreferenceService.php
app/Services/Communication/CommunicationProviderManager.php
app/Services/Communication/CommunicationService.php
app/Services/Communication/CommunicationTemplateService.php
app/Services/Communication/Contracts/CommunicationProvider.php
app/Services/Communication/Contracts/HandlesDeliveryWebhooks.php
app/Services/Communication/Data/DeliveryResult.php
app/Services/Communication/Data/IntegrationTestResult.php
app/Services/Communication/Data/OutboundMessage.php
app/Services/Communication/Data/WebhookStatusUpdate.php
app/Services/Communication/MessageContext.php
app/Services/Communication/Providers/LaravelMailEmailProvider.php
app/Services/Communication/Providers/TwilioSmsProvider.php
app/Services/Communication/Providers/WhatsAppCloudProvider.php
app/Services/Communication/TemplateRenderer.php
app/Services/Distribution/CareerApplicationService.php
app/Services/Distribution/Connectors/ApnaConnector.php
app/Services/Distribution/Connectors/CareerSiteConnector.php
app/Services/Distribution/Connectors/IndeedConnector.php
app/Services/Distribution/Connectors/LinkedInConnector.php
app/Services/Distribution/Connectors/NaukriConnector.php
app/Services/Distribution/Connectors/UnavailableJobBoardConnector.php
app/Services/Distribution/Connectors/WorkIndiaConnector.php
app/Services/Distribution/Connectors/XmlFeedConnector.php
app/Services/Distribution/DistributionResult.php
app/Services/Distribution/JobBoardConnector.php
app/Services/Distribution/JobBoardRegistry.php
app/Services/Distribution/JobDistributionService.php
app/Services/Distribution/RecruitmentCampaignService.php
app/Services/Integrations/Calendar/CalendarConnectionService.php
app/Services/Integrations/Calendar/CalendarManager.php
app/Services/Integrations/Calendar/CalendarProvider.php
app/Services/Integrations/Calendar/CalendarSyncService.php
app/Services/Integrations/Calendar/Data/CalendarEventData.php
app/Services/Integrations/Calendar/Data/CalendarEventResult.php
app/Services/Integrations/Calendar/Data/OAuthTokens.php
app/Services/Integrations/Calendar/Providers/GoogleCalendarProvider.php
app/Services/Integrations/Calendar/Providers/MicrosoftCalendarProvider.php
app/Services/Integrations/Contracts/Integration.php
app/Services/Integrations/IntegrationRegistry.php
app/Services/Integrations/Video/VideoMeetingProvider.php
app/Services/Integrations/Video/ZoomMeetingProvider.php
config/communications.php
config/services.php
database/factories/CalendarConnectionFactory.php
database/factories/CandidateCommunicationFactory.php
database/factories/CommunicationTemplateFactory.php
database/factories/JobPostingFactory.php
database/factories/RecruitmentCampaignFactory.php
database/migrations/2026_09_25_153038_create_communication_templates_table.php
database/migrations/2026_09_25_153039_create_communication_template_versions_table.php
database/migrations/2026_09_25_153040_create_candidate_communication_preferences_table.php
database/migrations/2026_09_25_153041_create_candidate_communications_table.php
database/migrations/2026_09_25_153042_create_communication_webhook_events_table.php
database/migrations/2026_09_25_153043_create_integration_statuses_table.php
database/migrations/2026_09_25_154556_create_calendar_connections_table.php
database/migrations/2026_09_25_154557_create_interview_calendar_events_table.php
database/migrations/2026_09_25_154558_add_meeting_provider_to_interviews_table.php
database/migrations/2026_09_25_155232_create_job_postings_table.php
database/migrations/2026_09_25_155233_create_job_distributions_table.php
database/migrations/2026_09_25_155235_add_origin_to_candidate_applications_table.php
database/migrations/2026_09_25_155518_create_recruitment_campaigns_table.php
database/migrations/2026_09_25_155519_add_campaign_to_applications_and_costs.php
database/migrations/2026_09_26_000001_grant_phase_five_permissions.php
docs/phase-5-communication-distribution.md
resources/views/careers/applied.blade.php
resources/views/careers/feed.blade.php
resources/views/careers/index.blade.php
resources/views/careers/show.blade.php
resources/views/filament/pages/integrations.blade.php
resources/views/mail/candidate-message.blade.php
routes/web.php
tests/Feature/Ai/ScheduleInterviewToolTest.php
tests/Feature/CalendarIntegrationTest.php
tests/Feature/CommunicationCenterUiTest.php
tests/Feature/CommunicationDistributionAnalyticsTest.php
tests/Feature/CommunicationServiceTest.php
tests/Feature/CommunicationWebhookTest.php
tests/Feature/EventDrivenCommunicationTest.php
tests/Feature/JobDistributionTest.php
tests/Feature/RecruitmentCampaignTest.php
PATHS
git diff --cached --stat | tail -1
git commit -m 'phase-5: communication and distribution' -m 'Communication templates, preferences and delivery; provider webhooks; calendar and video integrations; careers site; job distribution; campaigns.' -m 'Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>'
```

### Commit 4 — group D: `phase-6: automation and action os` (113 files)

```bash
git add --pathspec-from-file=- <<'PATHS'
.ai/rules/app-filament.md
.ai/rules/automation.md
app/Console/Commands/CleanupAutomation.php
app/Console/Commands/DispatchAutomationRules.php
app/Console/Commands/ProcessAutomationQueue.php
app/Enums/ActionPriority.php
app/Enums/AutomationActionStatus.php
app/Enums/AutomationExecutionStatus.php
app/Enums/AutomationFailureBehavior.php
app/Enums/AutomationRuleStatus.php
app/Enums/AutomationScope.php
app/Enums/EscalationStatus.php
app/Enums/RecruiterActionStatus.php
app/Enums/RecruiterActionType.php
app/Events/CommunicationFailed.php
app/Events/InterviewCompleted.php
app/Events/InterviewConfirmed.php
app/Filament/Concerns/GuardsDomainExceptions.php
app/Filament/Pages/AutomationDashboard.php
app/Filament/Pages/AutomationTemplates.php
app/Filament/Pages/NotificationCenter.php
app/Filament/Resources/AutomationEscalations/AutomationEscalationResource.php
app/Filament/Resources/AutomationEscalations/Pages/ListAutomationEscalations.php
app/Filament/Resources/AutomationExecutions/AutomationExecutionActions.php
app/Filament/Resources/AutomationExecutions/AutomationExecutionResource.php
app/Filament/Resources/AutomationExecutions/Pages/ListAutomationExecutions.php
app/Filament/Resources/AutomationExecutions/Pages/ViewAutomationExecution.php
app/Filament/Resources/AutomationExecutions/Schemas/AutomationExecutionInfolist.php
app/Filament/Resources/AutomationExecutions/Tables/AutomationExecutionsTable.php
app/Filament/Resources/AutomationRules/AutomationRuleActions.php
app/Filament/Resources/AutomationRules/AutomationRuleResource.php
app/Filament/Resources/AutomationRules/Pages/CreateAutomationRule.php
app/Filament/Resources/AutomationRules/Pages/DryRunAutomationRule.php
app/Filament/Resources/AutomationRules/Pages/EditAutomationRule.php
app/Filament/Resources/AutomationRules/Pages/ListAutomationRules.php
app/Filament/Resources/AutomationRules/Pages/ViewAutomationRule.php
app/Filament/Resources/AutomationRules/RelationManagers/ExecutionsRelationManager.php
app/Filament/Resources/AutomationRules/RelationManagers/VersionsRelationManager.php
app/Filament/Resources/AutomationRules/Schemas/AutomationRuleForm.php
app/Filament/Resources/AutomationRules/Schemas/AutomationRuleFormData.php
app/Filament/Resources/AutomationRules/Schemas/AutomationRuleInfolist.php
app/Filament/Resources/AutomationRules/Tables/AutomationRulesTable.php
app/Filament/Resources/Interviews/Tables/InterviewsTable.php
app/Filament/Resources/RecruiterActions/Pages/ListRecruiterActions.php
app/Filament/Resources/RecruiterActions/RecruiterActionResource.php
app/Filament/Resources/RecruiterActions/Tables/RecruiterActionsTable.php
app/Filament/Resources/RecruiterActions/Widgets/SuggestedNextActions.php
app/Filament/Widgets/Automation/AutomationStats.php
app/Jobs/RunAutomationExecutionJob.php
app/Models/AutomationActionExecution.php
app/Models/AutomationEscalation.php
app/Models/AutomationExecution.php
app/Models/AutomationRule.php
app/Models/AutomationRuleVersion.php
app/Models/RecruiterAction.php
app/Policies/AutomationEscalationPolicy.php
app/Policies/AutomationExecutionPolicy.php
app/Policies/AutomationRulePolicy.php
app/Policies/RecruiterActionPolicy.php
app/Services/AI/Tools/CandidateTools/RecommendNextStepTool.php
app/Services/Automation/Actions/ActionOutcome.php
app/Services/Automation/Actions/Contracts/AutomationAction.php
app/Services/Automation/Actions/Handlers/AddAuditEventAction.php
app/Services/Automation/Actions/Handlers/AddTimelineEventAction.php
app/Services/Automation/Actions/Handlers/CreateFollowupAction.php
app/Services/Automation/Actions/Handlers/CreateRecruiterActionAction.php
app/Services/Automation/Actions/Handlers/EscalateAction.php
app/Services/Automation/Actions/Handlers/HoldApplicationAction.php
app/Services/Automation/Actions/Handlers/MoveStageAction.php
app/Services/Automation/Actions/Handlers/NotifyAction.php
app/Services/Automation/Actions/Handlers/SendCommunicationAction.php
app/Services/Automation/AutomationActionRegistry.php
app/Services/Automation/AutomationEngine.php
app/Services/Automation/AutomationHealthService.php
app/Services/Automation/AutomationRuleService.php
app/Services/Automation/AutomationRuleValidator.php
app/Services/Automation/AutomationRuntime.php
app/Services/Automation/AutomationScopeResolver.php
app/Services/Automation/AutomationTemplateCatalog.php
app/Services/Automation/AutomationTime.php
app/Services/Automation/ConditionEvaluator.php
app/Services/Automation/Data/FieldDefinition.php
app/Services/Automation/Data/TriggerDefinition.php
app/Services/Automation/EscalationService.php
app/Services/Automation/RecipientResolver.php
app/Services/NotificationDispatchService.php
app/Services/RecruiterActionService.php
app/Services/RecruitmentActionCenterService.php
config/automation.php
database/factories/AutomationExecutionFactory.php
database/factories/AutomationRuleFactory.php
database/factories/RecruiterActionFactory.php
database/migrations/2026_09_25_185657_create_automation_rules_table.php
database/migrations/2026_09_25_185659_create_automation_rule_versions_table.php
database/migrations/2026_09_25_185700_create_automation_executions_table.php
database/migrations/2026_09_25_185701_create_automation_action_executions_table.php
database/migrations/2026_09_25_185704_create_recruiter_actions_table.php
database/migrations/2026_09_25_185705_create_automation_escalations_table.php
database/migrations/2026_09_26_000002_grant_phase_six_permissions.php
docs/phase-6-automation-action-os.md
resources/views/filament/pages/automation-dashboard.blade.php
resources/views/filament/pages/automation-templates.blade.php
resources/views/filament/resources/automation-rules/dry-run.blade.php
tests/Feature/AutomationAnalyticsHealthTest.php
tests/Feature/AutomationConditionTest.php
tests/Feature/AutomationEngineTest.php
tests/Feature/AutomationEscalationTest.php
tests/Feature/AutomationPerformanceTest.php
tests/Feature/AutomationRuleServiceTest.php
tests/Feature/AutomationTimeBasedTest.php
tests/Feature/AutomationUiTest.php
tests/Feature/NextBestActionServiceTest.php
tests/Feature/RecruiterActionServiceTest.php
PATHS
git diff --cached --stat | tail -1
git commit -m 'phase-6: automation and action os' -m 'Automation rule engine with versions, executions, escalations; Action Center; Next Best Action; Notification Center; templates, dry run, health.' -m 'Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>'
```

### Commit 5 — group E: `phase-7: edge intelligence` (91 files)

```bash
git add --pathspec-from-file=- <<'PATHS'
.ai/rules/app-models.md
.ai/rules/intelligence.md
.ai/rules/views-filament.md
app/Console/Commands/RefreshIntelligence.php
app/Enums/EvidenceType.php
app/Enums/HealthStatus.php
app/Enums/HiringRiskStatus.php
app/Enums/HiringRiskType.php
app/Enums/IntelligenceAiStatus.php
app/Enums/MemoryType.php
app/Enums/MetricStatus.php
app/Enums/RediscoveryResultStatus.php
app/Enums/RequirementLevel.php
app/Enums/RiskSeverity.php
app/Enums/RoleDnaCategory.php
app/Enums/RoleDnaOrigin.php
app/Enums/RoleDnaStatus.php
app/Enums/SignalBand.php
app/Enums/VerificationStatus.php
app/Events/HiringRiskDetected.php
app/Events/OfferStatusChanged.php
app/Events/RequisitionStatusChanged.php
app/Filament/Concerns/ShowsIntelligenceEvidence.php
app/Filament/Pages/IntelligenceOverview.php
app/Filament/Resources/HiringMemoryRecords/HiringMemoryRecordResource.php
app/Filament/Resources/HiringMemoryRecords/Pages/ListHiringMemoryRecords.php
app/Filament/Resources/HiringMemoryRecords/Pages/ViewHiringMemoryRecord.php
app/Filament/Resources/HiringRisks/HiringRiskResource.php
app/Filament/Resources/HiringRisks/Pages/ListHiringRisks.php
app/Filament/Resources/RecruitmentRequisitions/Pages/RequisitionIntelligence.php
app/Filament/Resources/RecruitmentRequisitions/Pages/ViewRecruitmentRequisition.php
app/Jobs/SummarizeHiringMemoryJob.php
app/Listeners/CaptureHiringMemory.php
app/Models/HiringHealthSnapshot.php
app/Models/HiringMemoryRecord.php
app/Models/HiringRisk.php
app/Models/IntelligenceEvidence.php
app/Models/RediscoveryResult.php
app/Models/RediscoveryRun.php
app/Models/RoleDnaProfile.php
app/Models/RoleDnaVersion.php
app/Models/TalentSignalSnapshot.php
app/Policies/HiringMemoryRecordPolicy.php
app/Policies/HiringRiskPolicy.php
app/Services/AI/Tools/IntelligenceTools/ExplainTalentSignalTool.php
app/Services/AI/Tools/IntelligenceTools/GetHiringHealthTool.php
app/Services/AI/Tools/IntelligenceTools/GetHiringMemoryTool.php
app/Services/AI/Tools/IntelligenceTools/GetRoleDnaTool.php
app/Services/AI/Tools/IntelligenceTools/ListHiringRisksTool.php
app/Services/AI/Tools/IntelligenceTools/RediscoverTalentTool.php
app/Services/AI/Tools/IntelligenceTools/ResolvesIntelligenceScope.php
app/Services/Intelligence/Data/EvidenceItem.php
app/Services/Intelligence/Data/TalentSignalResult.php
app/Services/Intelligence/EvidenceLookup.php
app/Services/Intelligence/EvidenceRecorder.php
app/Services/Intelligence/HiringHealthService.php
app/Services/Intelligence/HiringMemoryService.php
app/Services/Intelligence/HiringRiskRadar.php
app/Services/Intelligence/IntelligenceAiService.php
app/Services/Intelligence/IntelligenceText.php
app/Services/Intelligence/RoleDnaBuilder.php
app/Services/Intelligence/RoleDnaService.php
app/Services/Intelligence/TalentRediscoveryService.php
app/Services/Intelligence/TalentSignalCalculator.php
app/Services/Intelligence/TalentSignalService.php
app/Services/RequisitionApprovalService.php
config/intelligence.php
database/factories/HiringRiskFactory.php
database/migrations/2026_09_25_205447_create_intelligence_evidence_table.php
database/migrations/2026_09_25_205448_create_role_dna_profiles_table.php
database/migrations/2026_09_25_205449_create_role_dna_versions_table.php
database/migrations/2026_09_25_205451_create_talent_signal_snapshots_table.php
database/migrations/2026_09_25_205452_create_hiring_health_snapshots_table.php
database/migrations/2026_09_25_205453_create_hiring_memory_records_table.php
database/migrations/2026_09_25_205455_create_hiring_risks_table.php
database/migrations/2026_09_25_205456_create_rediscovery_runs_table.php
database/migrations/2026_09_25_205457_create_rediscovery_results_table.php
database/migrations/2026_09_26_000003_grant_phase_seven_permissions.php
resources/views/filament/intelligence/evidence.blade.php
resources/views/filament/intelligence/talent-signal.blade.php
resources/views/filament/pages/intelligence-overview.blade.php
resources/views/filament/resources/recruitment-requisitions/intelligence.blade.php
tests/Feature/HiringHealthAndRiskTest.php
tests/Feature/HiringMemoryTest.php
tests/Feature/IntelligenceAiTest.php
tests/Feature/IntelligencePerformanceTest.php
tests/Feature/IntelligenceRefreshTest.php
tests/Feature/IntelligenceUiTest.php
tests/Feature/RoleDnaTest.php
tests/Feature/TalentRediscoveryTest.php
tests/Feature/TalentSignalTest.php
PATHS
git diff --cached --stat | tail -1
git commit -m 'phase-7: edge intelligence' -m 'Evidence and provenance, Role DNA, Talent Signal, Hiring Health, Hiring Risk Radar, Talent Rediscovery, Hiring Memory, queued Intelligence AI, Copilot tools and UI.' -m 'Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>'
```

### Commit 6 — group F: `phase-7: provider failure handling` (7 files)

```bash
git add --pathspec-from-file=- <<'PATHS'
.ai/rules/a-i-providers.md
app/Jobs/GenerateRoleDnaSuggestionsJob.php
app/Services/AI/Contracts/LLMProviderInterface.php
app/Services/AI/Exceptions/AiProviderUnavailableException.php
app/Services/AI/Providers/GeminiProvider.php
app/Services/AI/Providers/OpenAiProvider.php
tests/Feature/Ai/ProviderIndependenceTest.php
PATHS
git diff --cached --stat | tail -1
git commit -m 'phase-7: provider failure handling' -m 'Gemini/OpenAI structured() throw on HTTP error, timeout and invalid JSON; Role DNA suggestions retry once; failure-mode tests.' -m 'Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>'
```

### Commit 7 — group G: `phase-7: final hardening and documentation` (9 files)

```bash
git add --pathspec-from-file=- <<'PATHS'
.ai/rules/intelligence-tools.md
.ai/rules/jobs.md
docs/backlog.md
docs/phase-7-architecture.md
docs/phase-7-commit-plan.md
docs/phase-7-discovery.md
docs/phase-7-freeze-and-commit-review.md
docs/phase-7-production-readiness.md
tests/Feature/PhaseSevenAccessMatrixTest.php
PATHS
git diff --cached --stat | tail -1
git commit -m 'phase-7: final hardening and documentation' -m 'Phase 7 discovery/architecture docs, freeze review, commit plan, production readiness, backlog, access-matrix test, recorded rules.' -m 'Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>'
```

### Commit 8 — group H: `phases 4-7: shared integration points` (55 files)

```bash
git add --pathspec-from-file=- <<'PATHS'
.ai/rules/app-services.md
.ai/rules/index.md
app/Console/Commands/DispatchRecruitmentAlerts.php
app/Console/Commands/SendCandidateReminders.php
app/Enums/CommunicationTrigger.php
app/Filament/Pages/Pipeline.php
app/Filament/Resources/CandidateApplications/Pages/ViewCandidateApplication.php
app/Filament/Resources/CandidatePortalAccounts/Schemas/CandidatePortalAccountInfolist.php
app/Filament/Resources/Candidates/CandidateResource.php
app/Filament/Resources/Candidates/Pages/ViewCandidate.php
app/Filament/Resources/Candidates/Schemas/CandidatePicker.php
app/Filament/Resources/EmployeeReferrals/Actions/ReferralActions.php
app/Filament/Resources/EmployeeReferrals/Pages/EditEmployeeReferral.php
app/Filament/Resources/EmployeeReferrals/Schemas/EmployeeReferralForm.php
app/Filament/Resources/RecruitmentRequisitions/RecruitmentRequisitionResource.php
app/Http/Controllers/Portal/DashboardController.php
app/Http/Requests/Portal/UpdateProfileRequest.php
app/Jobs/SendCommunicationJob.php
app/Listeners/SendCandidateCommunications.php
app/Listeners/TriggerAutomationRules.php
app/Models/Candidate.php
app/Models/CandidateApplication.php
app/Models/CandidatePortalAccount.php
app/Models/RecruitmentRequisition.php
app/Providers/AppServiceProvider.php
app/Providers/Filament/AdminPanelProvider.php
app/Services/AI/Tools/ToolRegistrar.php
app/Services/Automation/AutomationContext.php
app/Services/Automation/AutomationEventRegistry.php
app/Services/Automation/AutomationFieldRegistry.php
app/Services/Automation/AutomationLinks.php
app/Services/CandidatePortalService.php
app/Services/Communication/DeliveryStatusService.php
app/Services/HierarchyService.php
app/Services/InterviewSchedulingService.php
app/Services/InterviewService.php
app/Services/NextBestAction/NextBestAction.php
app/Services/NextBestAction/NextBestActionService.php
app/Services/OfferService.php
app/Services/RecruiterIncentiveCalculator.php
app/Services/RecruitmentAnalyticsService.php
app/Services/RecruitmentSlaService.php
app/Services/ReferralService.php
bootstrap/app.php
database/seeders/RecruitmentReferenceDataSeeder.php
database/seeders/RolePermissionSeeder.php
docs/phase-4-configurable-recruitment.md
resources/css/app.css
resources/views/components/portal/layout.blade.php
resources/views/filament/widgets/suggested-next-actions.blade.php
resources/views/portal/dashboard.blade.php
resources/views/portal/profile.blade.php
routes/console.php
tests/Feature/CandidatePortalTest.php
tests/Feature/InterviewSchedulingServiceTest.php
PATHS
git diff --cached --stat | tail -1
git commit -m 'phases 4-7: shared integration points' -m 'Files edited across phases: service provider and panel wiring, permission and reference seeders, schedules, and models/services extended by several phases.' -m 'Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>'
```

## 7. After the series

```bash
git status --short                      # expect empty
git log --oneline 9cba8e3..HEAD         # expect 8 commits
php artisan test --compact --parallel   # tip must still pass (1,113 tests)
```

Do not push until the user approves. Do not start Phase 8.

