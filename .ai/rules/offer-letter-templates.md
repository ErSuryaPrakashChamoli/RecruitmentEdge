---
paths:
  - 'app/Services/OfferLetterRenderer.php,app/Services/WordToPdfConverter.php,app/Models/OfferLetterTemplate.php,app/Filament/Resources/OfferLetterTemplates/**'
---

# Offer Letter Templates

## Word offer letter templates and the protected standard template
OfferLetterTemplate.format is RichText (panel editor) or Word (.docx with ${merge_tag} placeholders, stored privately on the local disk under offer-letter-templates/). Word files are only replaced through OfferLetterTemplateActions (download → edit → Upload New Version) or the form, and every upload must pass OfferLetterTemplateActions::guardPlaceholders() (unknown ${tags} rejected). The model deletes the superseded file on update/delete. The seeded system template (is_system) is the fallback of OfferLetterTemplate::defaultTemplate(): it can never be deleted or deactivated (model guard + policy + hidden actions + unselectable in bulk), only have its file replaced or restored via StandardOfferLetterDocument. Candidates always get a PDF: OfferLetterRenderer::pdf() fills the Word file and WordToPdfConverter converts it with LibreOffice (config services.libreoffice, installed in the Docker image), falling back to PhpWord+DomPDF. Tests must fake the converter or Process — never shell out to soffice.
