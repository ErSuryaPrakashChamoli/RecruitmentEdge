# Runbook: Leaked API Credential

**For:** whoever handles a report that an API credential (a `re_…` token) was exposed — in a repository, a log, a ticket, a screenshot or a third party's system.

**Status:** procedure. Every step uses pages and commands that exist in the application. Monitoring that would detect a leak by itself does not exist yet (production readiness: NO-GO, monitoring blocker).

**Never** paste the token into a ticket, chat or this runbook's record. Identify it by its **key id**: the 20 characters after `re_`, before the second `_`. The panel shows credentials as `re_<key id>_…`; the secret is never stored, only its SHA-256.

## 1. Contain (minutes)

1. **Find the credential.** The key id identifies the credential (unique across tenants). In the tenant's panel: **Administration → API Credentials**. It lists name, owner, scopes, expiry, last use and status.
2. **Revoke it.**
   - **In the panel:** the **Revoke** action, with a reason ("Leaked: <where found>", at most 255 characters). It needs `integrations.manage` in that tenant. It works even if the tenant is suspended or no longer entitled to the API.
   - **Effect:** the next request with the token gets 401. Nothing caches credentials, and an applicant intake already running re-checks the credential under a lock and is refused.
   - **Audit:** `api_credential_revoked`, with the reason.
3. **If you cannot reach a tenant administrator quickly,** a platform administrator can switch the tenant's API off as a whole:
   ```
   php artisan tenants:entitlement <tenant slug> api.access off --reason="Incident <id>: leaked credential"
   ```
   Every API request of that tenant then gets 403 `entitlement_required`. This is a blunt instrument: it stops every integration of the tenant. Remove the override with `--remove` once the credential is revoked.
4. There is **no bulk revocation**. If several credentials leaked, revoke each one.

## 2. Understand what it could reach

1. **Tenant and owner:** the credential's tenant and owner (panel row). A credential acts as its owner, narrowed by its scopes.
2. **Scopes:** the scopes on the credential limit what it could read or do. The only write the API offers is applicant intake.
3. **Last use:** `last_used_at` and `last_used_ip`. They are updated at most once a minute (`api.credentials.last_used_resolution`).
4. **Requests:** filter the logs on the credential's numeric id. Every API request writes one `api.request` line: route, method, status, duration, `credential_id`, plus `tenant_id` and `request_id` from the log context.
   - **Never the token:** the logs never hold it; redaction keeps the key id and masks the secret.
   - **Look for:** routes and statuses you do not expect; intake POSTs; a spike of 401 or 403 responses.
5. **Failed attempts:** a wrong secret for a known key id logs `api.authentication_failed` (`key_id`, `tenant_id`, `ip`). It is also audited as `api_authentication_failed`, at most once a minute per credential. Any 401 logs `api.authentication_refused`.
6. **Audit trail:** API actions are audited with actor kind `api` and the credential as actor. In **Administration → Audit log**, filter by actor kind `api` and by the period from the suspected exposure until the revocation; the request-id filter follows one request. Applicants created through the API carry the channel `api`.

## 3. Integrations and webhooks

1. A credential cannot change webhook endpoints or inbound sources: those are managed in the panel by `integrations.manage` holders, not through the API. Still check **Administration → Webhooks** for endpoints created or changed during the exposure window (audit `integration_connection_created` / `integration_connection_updated`).
2. If the leak came with an integration secret (a shared configuration file, for example), also follow `compromised-integration.md`.

## 4. Replace the credential

1. The **owner** issues a new credential (**Issue credential** on the API Credentials page) with the **smallest scopes** the integration needs and an expiry (1–365 days, default 90).
   - The token is shown once; deliver it to the integrator through a channel that is not the one that leaked.
   - **Rotate** cannot be used on a revoked credential. On an exposed but still-active credential, revoking is the containment step, so issue a new one.
2. If the tenant's API was switched off in step 1.3, remove the override:
   ```
   php artisan tenants:entitlement <tenant slug> api.access --remove --reason="Incident <id>: credential revoked and replaced"
   ```

## 5. Customer notification

Whether and how to tell the tenant (and anyone else) is an **owner / security / legal decision**. This runbook does not set notification duties or deadlines. Record what was exposed (scopes, period, last use) so that the decision can be made.

## 6. Close

- [ ] Credential revoked (audit `api_credential_revoked`), or the tenant's API off by override.
- [ ] Requests reviewed for the exposure window; findings written down.
- [ ] Replacement issued with minimal scopes, by its owner.
- [ ] Override removed, if one was set.
- [ ] The source of the leak removed (the repository, log or ticket) and the cause recorded.
- [ ] Notification decision recorded (owner / security / legal).

**Related:** `compromised-integration.md`, `security-incident.md`, `docs/saas-6-api-integrations.md`.
