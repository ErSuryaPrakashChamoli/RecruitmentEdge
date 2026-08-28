---
paths:
  - 'app/Services/OfferService.php,app/Services/InterviewService.php,app/Services/CandidateJoiningService.php,app/Events/OfferAccepted.php,app/Listeners/CreateJoiningRecordForAcceptedOffer.php'
---

# Listeners

## Offer/Interview/Joining services own their own stage sync; listeners aren't manually registered
OfferService, InterviewService, and CandidateJoiningService each call StageTransitionService directly inside their own DB transaction to keep CandidateApplication.current_stage in sync — never move an application's stage from a Filament page/action directly when one of these services exists for the change. OfferService::moveTo() dispatches OfferAccepted only on transition to Accepted; CreateJoiningRecordForAcceptedOffer listens for it and is picked up by Laravel's default event auto-discovery (bootstrap/app.php has no ->withEvents() override) purely because its handle() method type-hints OfferAccepted — do not also register it via Event::listen() in a provider, that would double-fire it. InterviewService::complete() requires at least one InterviewFeedback row to exist first (Section 15) and treats "this round's result" as distinct from the overall pipeline "Selected" decision (see InterviewService::selectCandidate()).
