---
paths:
  - 'app/Services/Outcomes/**'
  - app/Services/Outcomes/OutcomeLearningService.php
---

# Outcomes

## Outcome Loop: one writer, no invented or audit-log outcomes
Only OutcomeService writes hiring_outcomes (architecture test enforces it); records are immutable — correct/void/confirm create an audited new version and a ManualCorrection is never overridden by recalculation. Completed hires come from the joining record (CandidateJoined), never the Joined pipeline stage. Retention is observed going forward only (status on checkpoint day, medium confidence; inactive ≠ exit; separation record = high) — never read AuditLog as evidence and never backfill retention. Missing evidence is NotObserved/Unknown, never a failure; rates are withheld below outcomes.sample.insufficient_below (3). outcomes:evaluate only catches up events within catch_up_days (7) = OBSERVED_GOING_FORWARD; older history only via outcomes:backfill = BACKFILLED_DETERMINISTIC.

## Outcome learning is suggest-only and never a filter
OutcomeInsights change nothing until a reviewer (outcomes.review) accepts; reject needs a reason; every decision audited. Accepting Role DNA learning may add only a Preferred/Informational skill (never Required) via RoleDnaService::addAttribute, needs intelligence.role-dna.manage and a visible same-designation requisition. Accepted learning surfaces in RoleDnaBuilder::historicalAttributes (informational) and as Talent Signal 'outcome_patterns' context that must never affect the band. Compare skill keys only — no personal/protected attributes. AI explanations go through IntelligenceAiService::summarizeInsight (allowlisted aggregates, FAIRNESS_PATTERN + CAUSAL_PATTERN rejection); wording stays observational, never causal.
