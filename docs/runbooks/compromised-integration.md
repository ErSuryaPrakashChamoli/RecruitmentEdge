# Runbook: Compromised Integration

**For:** a tenant administrator and the platform on-call, when an integration connection may be compromised. That covers:
- an outbound webhook endpoint whose signing secret leaked, or whose URL now points somewhere it should not;
- an inbound source whose secret leaked, so forged events could be sent;
- the receiving system on the other side reported breached.

**Status:** procedure. The pages and services exist. Network egress controls that would also block an endpoint at network level do not exist yet (production readiness: NO-GO, egress blocker). The application's own guard still refuses private, link-local and metadata addresses at save and at send.

## 1. Contain: disable the connection

1. In the tenant's panel: **Administration → Webhooks** (holders of `integrations.manage`). Find the connection and choose **Disable**, with a reason ("Incident <id>: …").
   - Disabling needs **no entitlement** and works even if the tenant is suspended: an incident never waits for the plan.
   - **Audit:** `integration_connection_disabled`, with the reason.
2. What disabling stops, at once:
   - **New outbound events:** they are recorded only for active endpoints.
   - **Outbound deliveries already queued:** each is marked **Failed without being sent** and audited `webhook_delivery_failed`.
   - **Inbound requests:** refused with 403 `unavailable`.
   - **Inbound events already received:** processing re-checks the connection under a shared lock and marks the event **failed**, writing nothing.
3. What disabling does not stop:
   - a delivery whose HTTP call had already started when the disable committed. No lock is held over an HTTP call, a deliberate trade-off; it still records its result afterwards.
   - **To confirm,** check **Webhook Deliveries** for attempts in the minutes around the disable.
4. If the tenant's administrators cannot be reached, a platform administrator can stop every webhook of the tenant:
   ```
   php artisan tenants:entitlement <tenant slug> integrations.webhooks off --reason="Incident <id>: compromised integration"
   ```
   **Disable** itself stays available without the entitlement.

## 2. Rotate the secret

- **Rotate secret** (Webhooks page; active connections only; `integrations.manage`) issues a new secret. **Audit:** `integration_connection_secret_rotated`.
- **During a compromise, rotate after the connection is disabled and before it is enabled again**, and only once the other side is ready for the new secret.
- The previous secret **stays valid for 24 hours** (`api.webhooks.rotation_overlap_hours`):
  - outbound deliveries are signed with both secrets;
  - inbound events signed with either are accepted.

  A rotation alone therefore does **not** lock out a holder of the leaked secret for those 24 hours. The connection stays **disabled** until the overlap has passed, or until the other side confirms that its old secret is destroyed.
- Rotate requires the connection to be active. To rotate a disabled connection, **Enable** it (both need the `integrations.webhooks` entitlement) and **Rotate secret** at once, in one sitting, with the other side ready. Otherwise create a new connection and leave the compromised one disabled (there is no delete).

## 3. Investigate

1. **Webhook Deliveries:**
   - **Filter to this endpoint:** status (pending, sending, retrying, succeeded, failed), attempts, last answer and error, next attempt, replay count.
   - **What it carried:** payloads are thin (ids and states), so what left is "this record changed".
   - **Logs:** `webhooks.delivery` (`delivery_id`, `connection_id`, `outcome`, `status`, `attempt`) and `webhooks.delivery_job_failed`.
2. **Inbound Webhook Events:**
   - **Look at:** events of this source, by status (received, processing, processed, failed, ignored) and result.
   - **Bad signatures:** a request with a bad signature is logged as `webhooks.inbound_signature_invalid` (`connection_id`, `tenant_id`, `ip`) and sets the connection's last failure. It writes no audit row and no event.
   - **What a forged event could have done:** applicant intake, attributed to channel `webhook` and actor kind `integration`.
3. **Audit log:**
   - **Actions:** `integration_connection_*`, `webhook_delivery_replayed`, `inbound_webhook_reprocessed`, `webhook_delivery_failed`.
   - **Applicant intake:** filter by actor kind `integration` for intake done through the source.
4. **Configuration changes:** compare the endpoint URL and events with what the tenant expects; edits are audited as `integration_connection_updated`.
5. **Affected tenant:** the connection's own tenant only. Connections, deliveries and events are tenant-owned, and no request or payload can name another tenant.

## 4. Restore safely

1. Only after the cause is understood and the **tenant's administrator approves** in writing (record who and when).
2. Re-check the endpoint URL (https, a public address, an allowed port — the application refuses anything else at save and at send).
3. **Enable**, with the `integrations.webhooks` entitlement in place (audit `integration_connection_enabled`; the failure count resets).
4. **Rotate secret** at once, and hand the new secret over through a channel that is not the compromised one.
5. **Deliveries failed during the disable:** replay only what the receiver still needs. Use **Webhook Deliveries → Replay**, which works on succeeded or failed deliveries and is audited `webhook_delivery_replayed`. There is no replay limit, so replay deliberately.
6. **Inbound events failed during the disable:** **Inbound Webhook Events → Process again** (failed or ignored events; audited `inbound_webhook_reprocessed`). Only for events you have verified as genuine.

## 5. Close

- [ ] Connection disabled; time and reason recorded.
- [ ] Old secret no longer valid: rotation plus 24 h overlap passed, or the connection replaced.
- [ ] Deliveries and events of the window reviewed; forged inbound effects (if any) identified and handled.
- [ ] Re-enabled only with written approval, or left disabled.
- [ ] Entitlement override removed, if one was set.
- [ ] Notification decision (tenant, others) recorded: **owner / security / legal decision**, not set here.

**Related:** `webhook-failure-spike.md`, `leaked-api-credential.md`, `security-incident.md`, `docs/saas-6-api-integrations.md` §3–§4.
