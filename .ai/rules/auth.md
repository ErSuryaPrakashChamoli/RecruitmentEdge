---
paths:
  - 'app/Providers/Filament/AdminPanelProvider.php,app/Filament/Pages/Auth/**'
---

# Auth

## Tenant-less simple pages must not render the notifications bell
Filament's simple layout renders the DatabaseNotifications component whenever a user is signed in and the topbar is shown. App\Models\DatabaseNotification is tenant-owned, so any page outside /admin/{tenant} (MFA set-up, /admin/organisations) threw MissingTenantContext and returned a 500. The admin panel now turns notifications on only inside a tenant: ->databaseNotifications(fn (): bool => TenantContext::current()->hasTenant()). Simple pages keep their user menu, which holds sign-out. A new tenant-less page also must not query tenant-owned data in its view or its topbar. Test: MfaTest "the enrolment page opens outside any tenant".
