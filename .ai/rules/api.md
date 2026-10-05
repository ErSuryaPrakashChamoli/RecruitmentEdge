---
paths:
  - 'app/Services/Api/**'
---

# Api

## API credentials act as their owner; tenant only from the credential
An API request acts as the credential's owner (a member holding integrations.manage), re-checked every request (identityPermits, canAccessTenant, permission), plus tenant isUsable and the api.access entitlement. Scopes (ApiScope) only narrow; every route still applies the owner's policies and visibleTo/hierarchy scopes. Never read a tenant from the request. Only the SHA-256 of the secret is stored; the token is returned once (issue/rotate). Revocation needs no entitlement. Mutations go through IdempotencyService (unique claim + fingerprint) and re-check the tenant/credential under sharedLock inside the write transaction.
