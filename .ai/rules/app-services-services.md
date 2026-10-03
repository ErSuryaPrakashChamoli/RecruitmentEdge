---
paths:
  - 'app/Services/OfferService.php,app/Services/InterviewService.php'
  - 'app/Services/ReferralService.php,app/Services/RecruiterIncentiveCalculator.php'
---

# App Services Services

## Offer/interview transitions decide on the locked, fresh status
Phase 8.7 (D8.7-005): every OfferService::moveTo and InterviewService transition runs in a transaction that first locks the row (lockForUpdate) and refreshes the model, then validates the transition — a stale copy (double click, portal + recruiter) must be refused, not repeat the transition. Staff alerts for these transitions pass a dedupe key (offer keys include the status-history count so a re-release still alerts).

## A referral is Joined only on a Joined joining record; its sync locks the referral
P810-DI-02: ReferralService::syncFromApplication targets Joined only when the application's CandidateJoining (read from the DB, never the cached relation) is Joined; joining_date = that record's actual_doj (?? expected_doj), never now(). referralIncentiveIneligibility and calculateForReferralJoining check the same record (the latter throws DomainException). CandidateStageChanged is ShouldDispatchAfterCommit, so syncs run outside the application lock and can overlap: the sync runs in a transaction with RowLock::fresh($referral) and re-reads the application by id — never trust the event's application copy. Referrals/bonuses written before this rule are not re-synced backwards or re-priced (never-backwards check). Test fixtures must join through an accepted offer + createForAcceptedOffer + markJoined, not transitionTo(Joined).
