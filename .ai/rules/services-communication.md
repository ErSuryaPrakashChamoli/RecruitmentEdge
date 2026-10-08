---
paths:
  - 'app/Services/Automation/AutomationRuleService.php,app/Services/Communication/CommunicationTemplateService.php'
---

# Services Communication

## Automation lifecycle needs reasons and separation of duties; live templates cannot be archived
Phase 8.6 (D8.6-020…023): update (when configuration changes), activate, pause and archive take a required reason. The author of a rule's latest version cannot activate it (SEPARATION_OF_DUTIES_EXEMPT_ROLES: chro, vp_hr). configuration() includes priority and owner_id (versioned). Versions/executions/escalations cannot be deleted one by one. update() compares with the stored configuration (a refused save must not hide a change). A communication template used by an active/paused rule (action template_key or escalation candidate_template) cannot be archived; provider_template changes create a version and messages store communication_template_version_id.
