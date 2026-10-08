# Runbook: Application Key and Secret Rotation

**For:** the platform operator who holds production secrets. Use it:
- when a secret leaked, or may have leaked (from `security-incident.md`);
- on a planned rotation.

**Status:** the **application** procedure below uses commands that exist (`security:reencrypt`, `ops:preflight`, `queue:drain-status`). The **infrastructure** procedure does not exist yet:
- no production secret manager or vault is chosen;
- where production reads its environment from is undecided (production readiness: NO-GO, secrets blocker).

Each step that says "set it in the production secret source" must be mapped to that system once it exists. Until then, compose reads `.env` on the host.

**Never** print, paste or commit a key or secret value: not in a ticket, a chat, a log or this runbook's record. Refer to a secret by its **variable name** only.

## A. `APP_KEY` rotation

### What `APP_KEY` protects

| What | Where | After rotation |
|---|---|---|
| Encrypted columns | `integration_connections.secrets`; `calendar_connections.access_token`, `refresh_token`; `api_idempotency_keys.response_encrypted`; `inbound_webhook_events.payload_encrypted`; `users.app_authentication_secret`, `app_authentication_recovery_codes` (`App\Services\Operations\EncryptedColumns::COLUMNS`) | Readable through `APP_PREVIOUS_KEYS`; rewritten by `security:reencrypt` |
| Queued jobs, mails, notifications and listeners marked `ShouldBeEncrypted` | `jobs` and `failed_jobs` payloads | Readable through `APP_PREVIOUS_KEYS` until drained. **A failed job encrypted with a removed key can no longer be retried** |
| Cookies (the session cookie in the panels and the portal) | browsers | Readable through `APP_PREVIOUS_KEYS`. Sessions themselves are not encrypted (`SESSION_ENCRYPT=false`) |
| Signed links | candidate portal password links (48 h); scheduling invitations (until the invitation's own expiry); scheduling forms (30 min); booking links (14 days); private-file URLs (5 min) | Accepted with any key in `APP_PREVIOUS_KEYS`; broken once the old key is removed |
| The cached Zoom token | cache key `zoom:s2s-token:v2` | Expires within 50 minutes |

**Not protected by `APP_KEY`:**
- passwords (hashed);
- API credentials (SHA-256 of the secret);
- webhook signing secrets — those are inside `integration_connections.secrets`, so they *are* covered as an encrypted column.

### Preparation

- **Who:** an operator with write access to the production secret source, plus a second person to check each step (keys are irreversible to lose).
- **When:** outside a release window. **No migration** may run between rotation and re-encryption.
- **Backup first:** take and verify a backup (`backup-restore-verification.md`). Record **which key** that backup needs: a backup taken before the rotation needs the **old** key for as long as the backup is kept.
- **Keep the old key safe until step 7.** It is still needed: as a previous key, for older backups, and for the rollback below.

### Downtime

**No maintenance window is needed.** With the old key in `APP_PREVIOUS_KEYS`, everything already encrypted stays readable, and:
- sessions continue;
- links work;
- queued jobs run.

The only interruption is the container restart in step 3. Signed-in users are signed out only at step 7, when the old key is removed: their cookies were encrypted with it.

### Steps

1. **Generate a new key** on a trusted machine: `php artisan key:generate --show`. It prints the key and **does not write any file**. Never generate it into the repository's `.env`.
2. **Set it in the production secret source:**
   - `APP_KEY` = the new key;
   - `APP_PREVIOUS_KEYS` = the old key, comma-separated with any older ones still needed.
3. **Restart every container** so all of them read the same keys: `docker compose up -d`. Configuration is cached at container start.
   - **Workers matter most.** They are long-running processes that keep the configuration they started with. A worker left on the old configuration keeps writing with the old key, which then has to be re-encrypted again.
   - `docker compose ps` must show every service recreated and `healthy`. `php artisan queue:drain-status` must show a fresh heartbeat for each of the four workers.
   - If production does not run this compose stack, restart the workers and the scheduler with the production mechanism, gracefully (`docs/runbooks/queue-operations.md` §1).
4. **Verify readability:**
   - `php artisan security:reencrypt --dry-run` — counts values and `Unreadable`; it **changes nothing**. `Unreadable` must be 0.
   - `php artisan ops:verify-integrity` — its `Encrypted values` line must show 0 unreadable.
   - **If not 0:** a value is encrypted with a key that is in neither variable. **Stop.** Do not remove any key; find the missing key.
5. **Re-encrypt:** `php artisan security:reencrypt`.
   - Rows are rewritten one by one, only if unchanged since read, so it is safe beside live traffic and can be run again.
   - It ends with "Every encrypted value now uses the current key."
6. **Let the old key's other uses expire** before removing it:
   - **queues:** `php artisan queue:drain-status` — ready and reserved jobs drained (delayed jobs, such as reminders, may still carry the old key: wait for them or accept that they fail);
   - **failed jobs:** retry or discard them (`docs/runbooks/queue-operations.md` §3) — after removal they cannot be retried;
   - **signed links:** the longest outstanding is a booking link (14 days). Removing the key earlier breaks outstanding portal and booking links; candidates then need a new invitation. **How long to keep the previous key after a compromise is a security decision:** keeping it keeps a leaked key valid for links.
   - **the Zoom token:** 50 minutes.
7. **Remove the old key** from `APP_PREVIOUS_KEYS` in the production secret source. Then run `docker compose up -d`.
8. **Verify:**
   - `php artisan ops:preflight` — no `previous_keys` advice once the variable is empty;
   - `php artisan ops:verify-integrity` — 0 unreadable;
   - staff and candidates sign in again (old cookies are no longer readable).

**If the key leaked:**
- An attacker holding it can read the encrypted columns of any **copy** of the database they also hold (backups, a leaked dump), and can forge signed links and cookies until the key is removed.
- Remove the old key as soon as step 6 allows. Accept broken links rather than keep a compromised key.
- Treat backups taken with the old key as readable by the attacker.
- What to tell whom is an owner / security / legal decision (`security-incident.md`).

### Rollback considerations

| When | How to roll back |
|---|---|
| After step 2 or 3, before re-encryption | Swap back: the old key in `APP_KEY`, **the new key in `APP_PREVIOUS_KEYS`** (values written since step 3 use it), restart every container. Then rotate again later, from the start |
| After step 5 (re-encrypted) | Do **not** drop the new key: every value now needs it. Rolling back means the same swap. It keeps both keys until a later `security:reencrypt` under the restored key |
| After step 7 (old key removed) | Re-adding the old key to `APP_PREVIOUS_KEYS` makes old cookies, links and backups readable again. Do this only if the old key was **not** compromised |
| Any time | **Never** run with a key set that misses a key some value needs. `security:reencrypt --dry-run` and `ops:verify-integrity` (unreadable = 0) prove the set is complete |

**A lost `APP_KEY`** cannot be recovered. Without it, the encrypted columns of a restored database are unreadable (`docs/runbooks/backup-restore.md` §1).

## B. Other secrets

**General order:**
1. Rotate the secret at its issuer.
2. Set it in the production secret source.
3. `docker compose up -d`.
4. Verify.
5. Revoke the old one at the issuer.

| Secret (variable name) | Rotate how | Verify |
|---|---|---|
| `DB_PASSWORD` (and the database user) | Change the password in MySQL for the application user, update the secret source, restart **every** container (app, migrate, workers, scheduler) at once — a container on the old password fails | `/health/ready` 200; `queue:drain-status` heartbeats |
| `QUEUE_HEALTH_TOKEN` | New random value; update every monitoring client that calls `/health/*` with it | `/health/queue` with the new token → 200, with the old → 403 |
| `MAIL_PASSWORD` / mail provider keys | At the provider | The next mail sent (for example an invitation) arrives; no new failed jobs on the `notifications` queue |
| AI keys (`OPENAI_API_KEY`, `GEMINI_API_KEY`) | At the provider | An AI action; provider circuit closed |
| Messaging (`TWILIO_AUTH_TOKEN`, `WHATSAPP_CLOUD_TOKEN`, `WHATSAPP_CLOUD_APP_SECRET`, `WHATSAPP_CLOUD_VERIFY_TOKEN`) | At the provider. The app secret also signs inbound callbacks: update both sides together | An outbound message; an inbound callback accepted |
| Calendar and video (`GOOGLE_CALENDAR_CLIENT_SECRET`, `MICROSOFT_GRAPH_CLIENT_SECRET`, `ZOOM_CLIENT_SECRET`) | At the provider. Stored per-user calendar tokens are separate (encrypted columns; users reconnect if the provider revokes them) | A calendar sync; a Zoom meeting created (the token cache refreshes within 50 minutes) |
| Storage (`AWS_SECRET_ACCESS_KEY`), if S3 is used | At AWS (IAM) | `php artisan storage:audit`; a document download |
| A tenant's webhook signing secret | Not a platform secret: **Rotate secret** in the tenant's panel (`compromised-integration.md` §2) | |
| A tenant's API credential | Revoke and issue (`leaked-api-credential.md`) | |
| Billing provider secrets | **No live billing provider is configured** (only the fake provider, `BILLING_FAKE_WEBHOOK_SECRET`). Choosing one is an owner decision | |
| Backup encryption passphrase | `docs/runbooks/backup-restore.md` §2 step 4 — re-encrypt or retire the backups that used it, by the backup-policy decision | |

## C. Record

For each rotation, record:
- the variable **name**, the reason, who, when;
- the verification output;
- when the old value was revoked at the issuer, or removed from `APP_PREVIOUS_KEYS`.

**Never** record the values.

**Related:** `security-incident.md`, `docs/runbooks/incident-recovery.md` §6, `docs/runbooks/production-environment.md`, `docs/runbooks/backup-restore.md`.
