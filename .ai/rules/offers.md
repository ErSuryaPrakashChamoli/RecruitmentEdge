---
paths:
  - 'app/Services/OfferService.php,app/Services/OfferLetterIssuanceService.php,app/Models/OfferLetterTemplate.php,app/Filament/Resources/Offers/**'
---

# Offers

## Issued offer letters are stored; downloads serve the stored letter
Phase 8.6 (D8.6-010/011): OfferService::moveTo(Released) and releaseRevision() call OfferLetterIssuanceService::issue() inside the release transaction — an immutable offer_letters row (PDF on the local disk, sha256, source, template + template version). Downloads use pdfFor(): the stored letter if its hash matches, else a regenerated one flagged as such (pre-8.6 offers). OfferLetterTemplate content changes record OfferLetterTemplateVersion rows (first change captures the prior content as v1); superseded Word files are kept. A template with offers/letters/versions cannot be deleted (policy + model). Tests releasing offers should Storage::fake('local').
