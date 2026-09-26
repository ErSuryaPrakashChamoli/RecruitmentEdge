# Phase 7 Production Readiness

Reviewed 2026-09-26 at the Phase 7 freeze, before Phase 8. Covers everything uncommitted on `feature/sep_25_hrm` (Phases 4, 4.1, 5, 6 and 7).

## Verdict

**NOT READY: one blocker.** The Gemini API key must be rotated before production (§1). Once it is rotated, every other critical check below passes: queues, scheduler, migrations, permissions, visibility, audit, data integrity and tests.

---

## 1. Secrets

| Check | Result |
|---|---|
| `.env` tracked by git | ABSENT (ignored; only `.env.example` is tracked) |
| `.env.example` values | Keys only, every value blank |
| `GEMINI_API_KEY` in `.env` | PRESENT |
| Gemini key in tracked files / untracked files / git history (all refs) | ABSENT / ABSENT / ABSENT |
| Gemini key in `storage/logs`, `storage/`, `bootstrap/cache`, `public/`, docs, tests | ABSENT |
| Gemini key in the application database | ABSENT |
| Gemini key in earlier Claude Code session transcripts (chat) | **PRESENT: pasted into chat on 2026-08-28 and 2026-09-21** |
| Google `AIza…` / OpenAI `sk-…` patterns, private keys in the repo | ABSENT |
| Hard-coded credentials in app/config/routes | ABSENT (dev-only `AdminUserSeeder` default password, see TD-002) |
| Keys or tokens written to logs | None found. Gemini auth uses the `x-goog-api-key` header, so the key is never in a URL. Calendar token failures log only IDs. |

**Gemini key rotation required before production.** The key was exposed through chat. Create a new key in Google AI Studio or Cloud Console, restrict it to the Generative Language API, put it only in the production secret store/`.env`, then revoke the old key. Never commit the new key.

- [ ] Gemini key rotated and old key revoked
- [ ] Production `.env` created from the secret store, not copied from a developer machine

## 2. Environment

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, fresh `APP_KEY`
- [ ] `QUEUE_CONNECTION=database` (or redis), **never `sync`** in production. With `sync`, AI requests run inside the web request (safe but slow) and automation/communication jobs lose their retries.
- [ ] `AI_PROVIDER` and `GEMINI_API_KEY` (rotated) set, or AI left unconfigured. Every deterministic feature works without AI; AI screens then say "AI is not configured".
- [ ] Phase 5 provider credentials (WhatsApp, Twilio, Google/Microsoft calendar, Zoom) only for providers actually in use; blank ones stay disabled
- [ ] `composer install --no-dev --optimize-autoloader`. This also removes Laravel Boost's `_boost/browser-logs` dev route.
- [ ] `npm ci && npm run build`
- [ ] `php artisan storage:link`

## 3. Database

| Check | Result |
|---|---|
| `migrate:status` | 125 ran, 0 pending |
| New migrations in Phases 4–7 | 50, all additive in `up()`; drops appear only in `down()` |
| Permission grants | Shipped as migrations (`grant_phase_four/five/six/seven_permissions`), so no seeding is needed for existing roles |
| Integrity (Role DNA current version, one open risk per key, one current memory per chain, orphans, stuck AI or automation) | 0 violations |

- [ ] Back up production before deploying
- [ ] `php artisan migrate --force`
- [ ] Seed reference data only if missing: `php artisan db:seed --class=RecruitmentReferenceDataSeeder --force`. **Never run `db:seed` without `--class` in production**: `DatabaseSeeder` includes `AdminUserSeeder` (password `password`).
- [ ] Do not roll back migrations in production

## 4. Queue worker

Run under Supervisor/systemd with automatic restart. Phase 8.3 splits the work into two workers
so slow provider calls never delay candidate messages (the shipped `docker-compose.yml` runs the
same two services, `queue` and `queue-background`):

```bash
# time-sensitive: candidate messages, automation, in-app notifications
php artisan queue:work --queue=communications,automation,default --tries=3 --timeout=120 --max-time=3600
# slow provider work: AI, embeddings, calendar and job-board APIs, Outcome Loop / Hiring Memory capture
php artisan queue:work --queue=intelligence,integrations,default --tries=3 --timeout=300 --max-time=3600
```

Set `DB_QUEUE_RETRY_AFTER` above the longest `--timeout` (330), so a slow job is never handed to a
second worker while it is still running.

| Queue | Used by |
|---|---|
| `automation` | `RunAutomationExecutionJob` |
| `communications` | `SendCommunicationJob`, `SendCandidateCommunications` listener |
| `integrations` | `SyncInterviewCalendarJob`, `PublishJobDistributionJob` |
| `intelligence` | `GenerateRoleDnaSuggestionsJob`, `SummarizeHiringMemoryJob`, `SummarizeOutcomeInsightJob`, `IndexAiDocumentJob`, `ReindexKnowledgeArticleJob`, `CaptureHiringMemory` and `RecordHiringOutcomes` listeners |
| `default` | Filament database notifications and anything unrouted |

- Retries: AI jobs `tries = 2` (one retry after 60 s), then marked failed with an honest message. The uniqueness lock expires after 1 hour (`uniqueFor`).
- Stale requests: `intelligence:refresh` marks AI requests stuck in "processing" for over 60 minutes as failed, so they can be requested again.
- `failed_jobs`: 0. `jobs`: 1 old `Filament\Notifications\DatabaseNotification` on `default` from 2026-09-14 (development, never processed because no worker listened). It was left in place and will be delivered once a worker covers `default`.
- [ ] Run `php artisan queue:restart` after every deploy
- [ ] Monitor `failed_jobs`

## 5. Scheduler

Cron (one line):

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

| Command | Schedule | Notes |
|---|---|---|
| `incentives:release-matured` | daily 00:00 | |
| `notifications:dispatch-alerts` | hourly | skips checks superseded by active automation templates |
| `performance:snapshot` | daily 00:30 | |
| `offers:expire-lapsed` | daily 00:15 | |
| `interview-slots:expire` | hourly | Phase 4 |
| `jobs:sync-distributions` | daily 01:00 | Phase 5 |
| `communications:send-reminders` | hourly | Phase 5 |
| `recruitment:automation:dispatch` | every 15 min, no overlap | Phase 6 |
| `recruitment:automation:process` | every 5 min, no overlap | Phase 6 |
| `recruitment:automation:cleanup` | daily 02:00 | Phase 6 |
| `intelligence:refresh` | hourly, no overlap | Phase 7, deterministic; **never calls AI** (verified in code and by test) |

No duplicate schedules.

- [ ] Cron installed on exactly one server (or add `onOneServer()` before running several)

## 6. AI safety

| Check | Result |
|---|---|
| Role DNA prompt | Role data only: designation, department, configured skills, experience range, qualification, employment type, public job description. No candidate data. Regression test asserts no name, email or phone. |
| Hiring Memory summary prompt | Recorded facts minus `recruiter_id`, `application_code`, `offer_code`, `remarks`. No names, contacts or pay figures. Tested. |
| Intelligence Copilot tools | Candidate codes and application codes only. `rediscover_talent` was fixed at the freeze and tested. |
| Older Copilot tools | Still return candidate names to the provider. Pre-existing; product decision needed (P7-BACKLOG-006). |
| Provider failures (503, 500, 429, timeout, invalid JSON, malformed shape, empty body, unconfigured) | Throw `AiProviderUnavailableException`; never success-with-empty. The Role DNA job retries once, then shows "failed". A web request never errors when AI is down. |
| Fairness | Protected characteristics excluded from prompts and scoring. AI output is schema-constrained, validated, keyword-filtered and stored **unconfirmed**. Only human confirmation changes Role DNA. Rejected or invalid output changes nothing. |
| Tests | All AI tests use fakes and `Http::fake()`; no real key is used. |

## 7. Access and visibility

- Every Phase 6/7 route is behind Filament authentication; guests are redirected to login (tested).
- Page access per role is pinned by `PhaseSevenAccessMatrixTest`:

| Page | Recruiter | Asst. Manager | Manager | VP HR | CHRO | Employee |
|---|---|---|---|---|---|---|
| Intelligence overview, Risk register | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Hiring Memory | ✗ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Automation rules, executions, dashboard | ✗ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Action Center | own items | own + reports | own + reports | own + reports | all | own items |
| Notification Center | own | own | own | own | own | own |

- Action permissions: Role DNA editing and AI requests need `intelligence.role-dna.manage` (manager and above). Risk actions need `intelligence.risks.manage` (assistant manager and above). Memory corrections need `intelligence.memory.manage` (VP HR and CHRO). Rediscovery needs `intelligence.rediscover` (recruiter and above).
- Hierarchy: candidate and requisition visibility each have one definition (`Candidate::visibleTo`, `RecruitmentRequisition::scopeVisibleTo`), reused by screens, intelligence services, evidence lookup and Copilot tools. Another team's requisition intelligence returns 404; tampered evidence IDs return nothing (tested). Two known copies (cost-per-hire, referrals) are consistent (TD-001).

## 8. Audit

Recorded with actor, entity and time: Role DNA versions, attribute add/confirm/reject, Role DNA confirmation, AI request/result/failure, risk open/escalate/acknowledge/dismiss/resolve/auto-resolve, rediscovery runs and actions, Hiring Memory capture and corrections, evidence verification, health and signal refreshes. Payloads carry IDs, statuses and reasons, never secrets or contact details. The AI failure audit stores a generic message, not provider output. Queued AI rows record the requester on the request row (P7-BACKLOG-007).

## 9. Quality gate (2026-09-26)

| Check | Result |
|---|---|
| Test suite (serial) | 1,113 passed (4,121 assertions); baseline 1,091 + 22 freeze tests |
| Test suite (parallel) | 1,113 passed |
| Browser smoke: Phase 7 (fresh DB, AI off) | 20/20, no page errors, no 5xx |
| Browser smoke: Phase 6 regression | 24/24 |
| Pint | clean |
| `npm run build` | OK |
| `optimize:clear` | OK |
| `route:list` | 222 routes, no duplicates or debug routes |
| `schedule:list` | 11 commands as above |
| `git diff --check` | clean |

## 10. Known limitations (accepted)

Literal skill matching (P7-BACKLOG-001), exact location/qualification matching shown as "unknown", keyword fairness filter (P7-BACKLOG-002), SLA health counts the first 200 active candidates (P7-BACKLOG-003), and "Insufficient history" below 3 comparable hires. **Insufficient history is expected behavior, not a defect** (P7-BACKLOG-004).

## 11. Deploy order

1. Rotate the Gemini key (blocker).
2. Back up the database.
3. Deploy code; `composer install --no-dev`; `npm ci && npm run build`.
4. `php artisan migrate --force`.
5. `php artisan optimize` (config, route, view and event caches).
6. `php artisan queue:restart`; confirm the worker covers all five queues.
7. Confirm the cron runs `schedule:run`.
8. Smoke test: sign in as a recruiter and a manager, open Intelligence overview, a requisition's Intelligence page, the Risk register and the Action Center.
