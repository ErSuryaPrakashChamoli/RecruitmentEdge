# Runbook: Webhook Failure Spike

**For:** the platform on-call and the tenant's integration administrator, when outbound webhook deliveries start failing in numbers: an endpoint is down, slow, refusing, or rejecting signatures.

**Status:** procedure. The signals exist in the application, but **nothing alerts on them yet**:
- the counts in `/health/queue` do not raise a health problem;
- no monitoring vendor is configured (production readiness: NO-GO, monitoring blocker).

Until monitoring exists, a spike is noticed by a tenant, by the counts below, or by the logs.

## 1. Detect

- **`GET /health/queue`** (bearer `QUEUE_HEALTH_TOKEN`), section `integrations`:
  - `deliveries_failed_last_hour`;
  - `deliveries_retrying`;
  - `endpoints_circuit_open`;
  - `inbound_failed_last_hour`.

  Counts only, across tenants. They do not turn the endpoint's status to 503.
- **Logs:**
  - `webhooks.delivery` lines with `outcome` failed or retrying (`delivery_id`, `connection_id`, `status`, `attempt`);
  - `webhooks.delivery_job_failed`.
- **Audit:** `webhook_delivery_failed`, recorded when a delivery runs out of retries.
- **Tenant panel:**
  - **Administration → Webhooks** shows each endpoint's last success, last failure, last error and **failures in a row**;
  - **Webhook Deliveries** lists each delivery.

## 2. What the system already does (do not fight it)

- **Retries:** a failed attempt is retried after 60 s, 5 min, 30 min, 2 h, 6 h and 12 h (`api.webhooks.retry_delays`), then the delivery is **Failed** (audited). Nothing is retried forever.
- **Circuit:** after 5 failures in a row (`api.webhooks.circuit_failures`), the endpoint gets **one trial delivery per 5 minutes** (`circuit_cooloff_seconds`). The other deliveries wait, still due, with no attempt counted. A failing endpoint therefore does not occupy the shared worker.
- **Per-tenant budget:** at most `WEBHOOK_TENANT_DELIVERIES_PER_MINUTE` (default 120) deliveries per tenant per minute. Past it, deliveries wait; nothing is dropped.
- **Sweep:** `integrations:sweep` runs every 5 minutes per tenant (scheduler):
  - it turns a delivery stuck in **Sending** past its claim back to **Retrying**;
  - it re-queues due deliveries, up to 500 per run;
  - it prunes finished deliveries and events older than 30 days (`api.webhooks.retention_days`).

## 3. Triage

1. **One endpoint or many?**
   - **Webhooks page:** failures in a row.
   - **Logs:** group `webhooks.delivery` failures by `connection_id`.
   - **Many endpoints of many tenants at once** points at the platform: the workers' outbound network, DNS, or the `queue-background` worker. Check `docker compose ps`, `php artisan queue:drain-status` and the worker logs (`docs/runbooks/queue-operations.md` §9).
2. **What does the endpoint answer?** The **Answer** and **Error** columns of Webhook Deliveries:
   - a 4xx/5xx status;
   - "Could not reach the endpoint (…)" — connection or timeout;
   - "The endpoint is disabled or was removed";
   - an unsafe-destination message — the URL now resolves to a refused address.
3. **Signature rejections** (the receiver answers 401/403): the receiver may not have the current secret after a rotation. During the 24 h overlap both secrets sign. Agree with the receiver which secret it holds.
4. **Queue state:** `php artisan queue:drain-status` and `/health/queue` show the `integrations` queue's age. A growing age with an open circuit is expected (deliveries wait); a growing age without failures means the worker is down.

## 4. Act

| Situation | Action |
|---|---|
| The receiver is down, temporarily | Nothing. Retries, the circuit and the sweep carry it. Tell the tenant. |
| The receiver is gone or wrong for good | The tenant **disables** the endpoint (Webhooks → Disable, with a reason): queued deliveries are marked failed without sending. Fix the URL, then **Enable** |
| A URL now resolves to a private or metadata address | Treat as a possible compromise: `compromised-integration.md` |
| Many tenants, platform cause | Fix the platform cause (worker, network, DNS). Deliveries resume on their own when it is fixed |
| One tenant's volume crowds others | The per-tenant budget already applies; lower `WEBHOOK_TENANT_DELIVERIES_PER_MINUTE` only by an owner decision (D-S7-O11) |

**Preserve failed deliveries:** they are kept with their status, error and attempts until `integrations:sweep` prunes finished rows older than 30 days. Do not delete them by hand. They are the evidence and the replay source.

## 5. Replay after recovery

1. Once the receiver works again, **Webhook Deliveries → Replay** re-sends a **failed** (or succeeded) delivery. It needs `integrations.manage` and the entitlement; it is audited `webhook_delivery_replayed` and counted in `replay_count`.
2. There is **no replay limit** and no bulk replay. Agree with the receiver which deliveries it needs; payloads are thin, so a receiver can usually fetch the current state through the API instead.
3. Replays go through the same budget and circuit as normal deliveries.

## 6. Close

- [ ] Cause identified (receiver, URL, secret, platform).
- [ ] Endpoint fixed and enabled, or left disabled on the tenant's decision.
- [ ] Needed deliveries replayed (audited) — or the receiver reconciled through the API.
- [ ] `/health/queue` integration counts back to normal; failures in a row 0.
- [ ] Monitoring gap noted: these counts do not alert yet (production-readiness monitoring blocker).

**Related:** `compromised-integration.md`, `docs/runbooks/queue-operations.md`, `docs/saas-6-api-integrations.md` §3.
