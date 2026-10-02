---
paths:
  - 'app/Services/OfferLetterIssuanceService.php,app/Jobs/ConvertOfferLetterJob.php,app/Models/OfferLetterConversion.php,app/Filament/Resources/Offers/**'
---

# Resources Offers

## Word offer letters convert on the documents queue
Phase 8.9 (P89-PERF-024). DomPDF letters are still issued inside the release transaction.

For a Word template:
- issue() fills the .docx inside the release transaction.
- It records an OfferLetterConversion (PENDING) and returns null.
- ConvertOfferLetterJob is dispatched after commit.
- completeConversion() creates the immutable OfferLetter, with issued_at = the release time.
- failConversion() marks the conversion FAILED and alerts the releaser.

Callers must handle "no letter yet": use pendingConversionFor(). Until the PDF exists, pdfFor() returns the filled .docx (format 'docx').

Never call WordToPdfConverter from a request.
