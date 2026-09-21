---
paths:
  - 'app/Services/OfferLetterRenderer.php,app/Models/OfferLetterTemplate.php,app/Filament/Resources/OfferLetterTemplates/**,app/Filament/Resources/Offers/**,resources/views/pdf/offer-letter*.blade.php'
---

# Pdf

## Offer letters render through OfferLetterRenderer
Never render an offer letter PDF straight from a Blade view — use OfferLetterRenderer::pdfViewFor($offer). Body precedence: offers.offer_letter_body (per-offer customisation) > the offer's chosen template if still active > OfferLetterTemplate::defaultTemplate() > built-in pdf.offer-letter view. Templates are Filament RichEditor HTML with merge tags (<span data-type="mergeTag" data-id="...">); add new tags to OfferLetterRenderer::MERGE_TAGS AND mergeTagValues() together. Always output via RichContentRenderer::toHtml() (sanitized), never toUnsafeHtml(). Filled merge tags keep their span wrapper, so assert on strip_tags() output in tests. Templates are managed with settings.manage; tailoring one offer's letter uses OfferPolicy::update and is only allowed while the offer is Draft/Initiated/Released.
