---
paths:
  - 'app/Services/Automation/**'
---

# Automation

## Automation actions call owning engines; rules run on their version snapshot
Automation (Phase 6) never writes domain data directly: action handlers call CommunicationService, NotificationDispatchService, RecruiterActionService, StageTransitionService, CandidateTimelineService or AuditLog. New action types = new handler in AutomationActionRegistry::HANDLERS; new condition facts = AutomationFieldRegistry; new triggers = AutomationEventRegistry (map real events, add a TriggerAutomationRules method). Executions run the rule *version* snapshot, not the live rule. TriggerAutomationRules must stay synchronous (it only inserts execution rows) — AutomationRuntime loop prevention depends on it. Never automate hiring decisions: MoveStageAction::ALLOWED_STAGES stays early/non-decision.
