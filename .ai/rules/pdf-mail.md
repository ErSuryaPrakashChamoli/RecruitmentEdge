---
paths:
  - 'resources/views/careers/**,resources/views/mail/candidate-*.blade.php,resources/views/components/portal/**,resources/views/pdf/**,app/Mail/Candidate*.php'
---

# Pdf Mail

## Tenant surfaces carry the tenant's name, not config('app.name')
SaaS-5 (S1-08): careers site and feed, candidate portal, candidate mails, candidate messages ({{company.name}}), offer letters and incentive statements show App\Services\Branding::tenantName() (offer letters: tenantLegalName()). A queued candidate mailable captures the name in its constructor (inside the tenant). config('app.name') / Branding::platformName() belongs only on platform surfaces: panels, staff invitations, platform mail, AI Copilot.
