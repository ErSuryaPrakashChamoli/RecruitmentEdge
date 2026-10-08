---
paths:
  - 'app/Services/NotificationDispatchService.php,app/Services/Automation/**,app/Services/RecruiterActionService.php'
---

# Automation Services

## Departed staff get no work: reachability, alert routing, automation owner re-check
Phase 8.4: "reachable" (RecipientResolver::isReachable) = current employee, not deleted, permitted login. NotificationDispatchService::alert re-routes an unreachable staff recipient to the nearest reachable manager, then an HR admin (identity.handoff_fallback_permission), else drops and logs — never send alerts around it. AutomationEngine re-checks the rule owner's access, automation.activate and scope before each run and pauses the rule otherwise; activation by another authorised user transfers ownership. OwnershipHandoffService pauses owned rules, moves Action Center items and counts open work into ownership_handoffs; historical owner columns are never rewritten. Factory automation rules get an owner with automation permissions.
