---
paths:
  - 'app/Services/Automation/**'
---

# Automation

## Automation actions call owning engines; rules run on their version snapshot
Automation (Phase 6) never writes domain data directly: action handlers call CommunicationService, NotificationDispatchService, RecruiterActionService, StageTransitionService, CandidateTimelineService or AuditLog. New action types = new handler in AutomationActionRegistry::HANDLERS; new condition facts = AutomationFieldRegistry; new triggers = AutomationEventRegistry (map real events, add a TriggerAutomationRules method). Executions run the rule *version* snapshot, not the live rule. TriggerAutomationRules must stay synchronous (it only inserts execution rows) — AutomationRuntime loop prevention depends on it. Never automate hiring decisions: MoveStageAction::ALLOWED_STAGES stays early/non-decision.

## Automation acts on the owner's current authority, per action
Phase 8.7: at run time AutomationEngine::perform re-checks owner authority, then AutomationScopeResolver::outsideAuthority (scope + owner hierarchy visibility) → Skipped + automation_skipped_authority. Each action needs AutomationActionRegistry::PERMISSIONS[type] held by the owner — a new action type that changes data must add its permission there. Escalations run the same checks in EscalationService::process. Everything runs inside AuditLog::asActor('automation', owner_id): never record automation as the owner (user_id null, on_behalf_of = owner). Action handlers must be redo-safe (a crashed run can re-execute a Pending row): find your earlier result by execution id + position. Candidate messages go through SendCommunicationAction::sendWithinDailyCap (per-candidate lock).
