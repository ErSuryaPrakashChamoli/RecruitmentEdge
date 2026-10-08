---
paths:
  - 'app/Services/OfferService.php,app/Services/InterviewService.php,app/Services/CandidateJoiningService.php,app/Events/OfferAccepted.php,app/Listeners/CreateJoiningRecordForAcceptedOffer.php'
---

# Listeners

## Offer/Interview/Joining services own their own stage sync; listeners aren't manually registered
OfferService, InterviewService, and CandidateJoiningService each call StageTransitionService directly inside their own DB transaction to keep CandidateApplication.current_stage in sync — never move an application's stage from a Filament page/action directly when one of these services exists for the change. OfferService::moveTo() dispatches OfferAccepted (ShouldDispatchAfterCommit) only on transition to Accepted; since Phase 8.3 the joining record is created inside that same transaction by CandidateJoiningService::createForAcceptedOffer() — the old CreateJoiningRecordForAcceptedOffer listener was removed. Listeners are picked up by Laravel's default event auto-discovery (bootstrap/app.php has no ->withEvents() override) — do not also register them via Event::listen() in a provider, that would double-fire them. InterviewService::complete() requires at least one InterviewFeedback row to exist first (Section 15) and treats "this round's result" as distinct from the overall pipeline "Selected" decision (see InterviewService::selectCandidate()).
