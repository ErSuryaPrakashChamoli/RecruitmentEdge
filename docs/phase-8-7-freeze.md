# Phase 8.7 Freeze: Platform Reliability, Queue, Automation & Communications Integrity

**Status: COMPLETE — frozen.** Phase 8.8 is not started (discovery only, when approved).

| | |
|---|---|
| Branch | `feature/sep_25_hrm` |
| Baseline | `fa4e858` (Phase 8.6 freeze) |
| Frozen at | the commit that adds this document (after `f2c0691`) |

## Release gate

| Gate | Result |
|---|---|
| Migrations | 158 → **160**, both additive and nullable. MySQL 8.4 throwaway: `migrate:fresh --seed` OK; rollback of the two 8.7 migrations removes their columns; re-migrate restores them. Development database: 0 pending. |
| Routes | 235 → **237**: `GET /health/queue` and the Queue health page. No other change. |
| Tests, parallel (4 processes) | **1,873 passed**, 20,362 assertions |
| Tests, serial | **1,873 passed**, 20,362 assertions |
| Phase 8.7 tests | `tests/Feature/Reliability` (10 files, 69 tests) + `MetricGovernanceArchitectureTest` (3) + topology, UI and portal additions |
| Mutation checks | **34 of 34** safeguard removals caught (below) |
| Browser, Phase 8.7 | **15/15** (real Chromium, throwaway database) |
| Browser regression | Phase 8.6: 16/16 · 8.5: 15/15 · 8.4: 19/19 (MFA enforced, database sessions) · 8.3: 17/17 · 8.2: 20/20 · 8.1: 12/12 · Phase 7: 20/20 · Phase 6: 24/24. Regressions ran against the new three-worker topology (8.6 on the sync queue, as it was built). |
| Browser console | none new. The Phase 8.4 run logs the same Filament dialog message (`showModal` on an already-open dialog, while the VP's session is ended mid-page by design) that it has logged in every run since Phase 8.4; its checks pass. |
| Flaky tests | `StageHistoryEventTest` (discovery) was fixed at the root in 8.6 (`a6aaffa`) and passed 8/8 repeated runs; `ConversionMetricsTest` time-of-day flake fixed (`5a34271`). |
| Governance audit | development database: 0 errors, 1 warning — unchanged from the 8.6 freeze |
| Performance | `phase-8-7-performance.md` Part A (100k applications, 520 open requisitions) |
| Security | `phase-8-7-security-review.md` Part A: 0 Critical, 0 High, 0 Medium open; 14/14 discovery findings closed |
| Pint | clean |
| `npm run build` | OK |
| `optimize:clear` | OK |
| Static analysis | not installed in the project (not added) |
| `git diff --check` | clean |

## Required test categories (brief §33)

| # | Category | Tests |
|---|---|---|
| 1 | payload privacy | `QueuePayloadPrivacyTest` (real `jobs` payloads) |
| 2 | log redaction | `SensitiveDataRedactionTest` |
| 3 | consent suppression | `CommunicationDeliveryIntegrityTest` opt-out; browser 9 |
| 4 | stale candidate state | application closed, candidate joined (`CommunicationDeliveryIntegrityTest`) |
| 5 | stale interview state | interview cancelled / moved; stale interview copy (`TransitionIdempotencyTest`) |
| 6 | stale offer state | offer withdrawn; second acceptance refused |
| 7 | stale automation authority | scope, visibility, lost permission (`AutomationExecutionAuthorityTest`) |
| 8 | stale escalation authority | three escalation tests |
| 9 | duplicate execution | double-submitted send, redo of automation actions, reschedule alert once, unique calendar job |
| 10 | concurrent execution | stale-copy transitions under row locks; message cap lock; scan lock (`RiskRadarCoverageTest`) |
| 11 | retry behaviour | circuit breaker hold and drain; retry/backoff contract (`QueueContractTest`); AI retryable errors |
| 12 | permanent failure | handoff exhausted; message stuck Sending → Failed |
| 13 | failed() handling | `QueueContractTest`; handoff audit + alert; redacted run failure |
| 14 | scheduler overlap | `SchedulerReliabilityTest` |
| 15 | worker timing | `QueueTopologyTest` (retry_after > timeout, grace ≥ retry_after, `.env.example`, `setpriv`); health-check retry_after alert |
| 16 | Risk Radar > 200 requisitions | `RiskRadarCoverageTest` (205 requisitions; command covers 203) |
| 17 | correlation propagation | `CorrelationAndActorTest` |
| 18 | async actor authority | AI requester lost access; automation on owner's behalf |
| 19 | queue-health authorization | `QueueHealthTest`; browser 5, 13–15 |
| 20 | metric-governance protection | `MetricGovernanceArchitectureTest` |
| 21 | failed-job cleanup | prune schedule and retention test |
| 22 | sweeper idempotency | sweep run twice handles each item once |
| 23 | resend safety | resend new row + audit; permission, status and consent checks |

## Mutation checks (safeguard removed → test must fail)

All 34 were caught:

1. Risk Radar 200-requisition cap restored
2. payload encryption / `SerializesModels` removed
3. send-time guard
4. circuit breaker hold
5. queue-time snapshot
6. automation record-authority check
7. per-action permission
8. cancel pending runs on edit
9. escalation scope check
10. escalation effective dates
11. reclaim-once
12. offer fresh status under lock
13. interview fresh status under lock
14. calendar operation from current state
15. distribution stale check
16. atomic Hiring Memory
17. reschedule alert dedupe
18. `failed()` removed from a job
19. scheduled task without guards
20. per-item isolation
21. scheduler heartbeat
22. `notifications` queue unconsumed
23. worker without grace period
24. endpoint authorization
25. platform alert dedupe
26. incentive offers definition changed
27. portal timeline filter
28. portal link encryption
29. follow-up redo guard
30. hold redo guard
31. timeline note redo guard
32. held early webhook
33. rule editor default
34. reindex of an unpublished article

## Freeze conditions (brief §40)

| Condition | |
|---|---|
| All approved D8.7 decisions implemented | ✔ (product options not chosen are backlog: D8.7-022 c, D8.7-025 b, D8.7-027 c, D8.7-028 b) |
| No unauthorized scope expansion | ✔ |
| Risk Radar > 200 fixed | ✔ |
| Queue payload privacy | ✔ |
| Sensitive logs redacted | ✔ |
| Failed-job lifecycle | ✔ |
| Communication execution-time checks | ✔ |
| Automation authority re-checked | ✔ |
| Escalation authority re-checked | ✔ |
| Offer / interview idempotency | ✔ |
| Deterministic execution keys | ✔ |
| Retry/backoff standardized, `failed()` complete | ✔ |
| Sweepers / recovery verified | ✔ |
| Scheduler overlap and fault isolation | ✔ |
| Worker drain / retry_after aligned | ✔ |
| Correlation ids, async actor context | ✔ |
| Queue health and approved alerts | ✔ |
| Metric governance decision handled | ✔ (a) implemented; (b) awaiting approval |
| Security findings closed or approved-deferred | ✔ all closed |
| Data-quality findings reconciled | ✔ (below) |
| Performance validated | ✔ |
| Full and browser regression green | ✔ |
| Working tree clean | ✔ at this commit |
| Documentation complete | ✔ |

## Data-quality findings (DQ-87-01…18)

| ID | Finding | Result |
|---|---|---|
| 01 | Risk Radar false auto-resolution > 200 | **Fixed** (`f127dd7`) |
| 02 | duplicate current Hiring Health / Talent Signal snapshots | **Fixed** (row lock, `eaa5e5c`) |
| 03 | partial Hiring Memory records | **Fixed** (one transaction) |
| 04 | messages stuck Queued / Sending | **Fixed** (`reliability:sweep`) |
| 05 | candidate-visible timeline entry for unsent messages | **Fixed** (portal shows only messages that went out) |
| 06 | log-transport email marked Sent | **Fixed** (`delivered_externally`) |
| 07 | stored WhatsApp body differs from the delivered template | **Deferred (inherent, documented):** provider templates are approved by Meta; the stored text is the local rendering with the same parameters |
| 08 | delivery status lost when a webhook precedes the message id | **Fixed** (held and applied) |
| 09 | runs stuck Running; AI calls stuck Approved | **Fixed** (reclaim once / sweep) |
| 10 | async audit rows without actor; scheduler rows without request id | **Fixed** |
| 11 | duplicate follow-ups / notes on redo; hold marked failed | **Fixed** |
| 12 | offer accept / expire race | **Fixed** (row lock, fresh status) |
| 13 | interview transitions without locks | **Fixed** |
| 14 | reschedule A→B→A key collision | **Fixed** (`b4021ee`) |
| 15 | orphan calendar events / Zoom meetings | **Fixed** (state-derived, unique calendar job) |
| 16 | subject overflow; `links.scheduling` never supplied | overflow **Fixed**; `links.scheduling` **Deferred** (P87-BACKLOG-003, product/security) |
| 17 | recruiter daily metrics vs governed offer dating | **Deferred** by decision (D8.7-025 b needs approval); guarded and pinned |
| 18 | knowledge reindex dropped while running | **Fixed** (unique until processing) |

No historical data was repaired.

## Known limitations

See `phase-8-7-implementation.md` §6: D8.7-025 (b) pending approval; `links.scheduling`; WhatsApp body; database queue scale limit (~100k applications / 500 open requisitions); no back-fill of new columns; no multi-process load test; cache store must be shared.

## Hotfix (D8.6-030)

**Status: NOT deployed, NOT pushed, NOT modified in Phase 8.7.**

- `hotfix/filament-delete-authorization` @ `2fab3fd` remains separate from this branch.
- The manual-joining `create()` authorization fix (SEC-86-I-01) is **required** before that hotfix is deployed to production.
- Production deployment remains a manual release action for the product owner (procedure: `phase-8-6-implementation.md` §7).

## Phase 8.8 handoff

| | |
|---|---|
| Phase 8.8 baseline | the Phase 8.7 freeze commit |
| Phase 8.8 | **DISCOVERY ONLY / NOT STARTED** |
| Items waiting on 8.8 | legal retention for `failed_jobs`, logs, audit and issued letters (P87-BACKLOG-008, P86-BACKLOG-004/009) |
