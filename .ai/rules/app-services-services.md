---
paths:
  - 'app/Services/OfferService.php,app/Services/InterviewService.php'
---

# App Services Services

## Offer/interview transitions decide on the locked, fresh status
Phase 8.7 (D8.7-005): every OfferService::moveTo and InterviewService transition runs in a transaction that first locks the row (lockForUpdate) and refreshes the model, then validates the transition — a stale copy (double click, portal + recruiter) must be refused, not repeat the transition. Staff alerts for these transitions pass a dedupe key (offer keys include the status-history count so a re-release still alerts).
