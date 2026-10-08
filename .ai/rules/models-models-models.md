---
paths:
  - 'app/Services/StageTransitionService.php,app/Services/PipelineTemplateService.php,app/Services/StageConfigurationService.php,app/Models/RecruitmentStage.php,app/Models/RequisitionPipelineStage.php,app/Models/CandidateApplication.php'
---

# Models Models Models

## Configurable pipelines map onto the canonical CandidateStage via `milestone`
Phase 4 stages (RecruitmentStage) never replace the CandidateStage enum: every stage has a `milestone`, and CandidateApplication.current_stage always stores that milestone, so funnel/SLA/incentive code keeps working. The configured stage lives in candidate_applications.pipeline_stage_id → requisition_pipeline_stages, an immutable per-requisition snapshot (updates other than superseded_at and deletes throw). Re-applying a template supersedes the old rows and remaps applications; never delete snapshot rows, because history points at them. Template stage order must be non-decreasing by milestone. Moves: transitionTo(enum) is for domain services (the pipeline stage follows automatically); moveToStage() enforces the configured rules (explicit transitions, non-skippable, terminal, requirements, remarks). An override needs pipeline.override plus a reason, is flagged is_override and AuditLogged, and can never go back to an earlier milestone. A new application gets its initial pipeline stage in CandidateApplication's creating hook. Every change dispatches CandidateStageChanged (after commit).
