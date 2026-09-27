# Phase 8.7 Commit Plan

**Branch:** `feature/sep_25_hrm`. **Base:** `fa4e858` (Phase 8.6 freeze).

**Git rules followed:** nothing pushed; no history rewritten, reset or squashed; the hotfix branch `hotfix/filament-delete-authorization` (`2fab3fd`) was not touched.

## Commits

| # | Commit | Group (brief §38) | Content |
|---|---|---|---|
| 1 | `f127dd7` | 3 Risk Radar integrity | every open requisition scanned in chunks; resolve only after a full scan; scan lock; `intelligence:refresh` covers all |
| 2 | `b4021ee` | 2 payload privacy | ids-only events, encrypted listeners/notifications/mails, `notifications` queue, atomic alert dedupe, listener re-reads |
| 3 | `fd564e3` | 2 / 8 logs and failed jobs | central log redaction, redacted `failed_jobs`, daily pruning |
| 4 | `cfacce0` | 12 correlation / actor | request ids for commands and jobs, `actor_kind` / `on_behalf_of`, `origin_request_id`, AI requester re-check |
| 5 | `5a34271` | 15 tests | test-only: time-of-day flake in `ConversionMetricsTest` |
| 6 | `b30455d` | 5 / 7 / 9 communications | send-time guard, circuit breaker, `reliability:sweep`, resend, `delivered_externally`, deterministic manual/AI keys |
| 7 | `2468e83` | 4 / 6 automation and escalation | scope, visibility and per-action permission at run time; edit cancels pending runs; cap lock; escalation re-checks; stuck-run reclaim; handoff `failed()`; platform alerts |
| 8 | `eaa5e5c` | 7 / 8 idempotency | offer and interview locking, alert keys, state-aware unique calendar/distribution jobs, atomic Hiring Memory, snapshot locks, queue contract tests |
| 9 | `627b939` | 10 scheduler | overlap guards, one server, background, per-item isolation, heartbeat |
| 10 | `e974ebf` | 11 workers | three workers, `notifications` consumer, grace period, `setpriv`, `retry_after` alignment |
| 11 | `b3c435b` | 13 queue health | page, endpoint, `queue:health-check` alerts |
| 12 | `5461c73` | 14 metric governance | `MetricGovernanceArchitectureTest` (D8.7-025 a) |
| 13 | `4397ec8` | data quality | portal timeline, queued portal link, automation redo, early webhooks |
| 14 | `e1c9144` | security | redacted exception text on runs and messages |
| 15 | `5c92f79` | fixes from the browser smoke | schedule in web requests, rule-editor default |
| 16 | `991617f` | 15 tests | sweeper idempotency, knowledge reindex skip |
| 17 | `f2c0691` | 16 documentation / runbook | implementation, security, performance, commit plan, runbook, `.ai/rules`, backlog |
| 18 | (this commit) | 17 freeze | `phase-8-7-freeze.md` |

## Grouping notes

- The brief's group 1 (baseline / infrastructure preparation) needed no commit: the baseline was verified and unchanged.
- Correlation/actor context (group 12) was committed before the communication and automation groups because both use `AuditLog::asActor` and `origin_request_id`.
- Retry/failure handling (group 8) is spread across the groups that own each job, with the contract test in commit 8.
- Test updates reflecting approved behaviour changes are committed with the change that required them.

## Deployment

See `phase-8-7-implementation.md` §7 and `docs/runbooks/queue-operations.md` §1.
