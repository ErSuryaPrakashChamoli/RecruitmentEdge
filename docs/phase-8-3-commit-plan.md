# Phase 8.3: Commit Plan

Phase 8.3 was committed incrementally on `feature/sep_25_hrm`, on top of the Phase 8.2 release (`7c4e945341cb32f32cbd2a247bddbdc7afff7564`). **Nothing has been pushed.**

**State of each commit:**
- Built and passed the full suite in parallel. The count shown in the table is from that commit.
- Pint-clean and scanned for secrets.
- Migrations are additive.

**Deviations from the suggested sequence:**
- **Cascades are their own commit (6),** together with the after-commit event rule. They needed the interview, offer and joining services of commits 3–5 to exist first.
- **Browser-found fixes are in commit 12.** The browser smoke found two real defects, fixed there: a negative time-to-hire crash, and the Copilot continuation error after an approval.

## Commits

| # | Commit | Contents | Suite |
|---|---|---|---|
| 1 | `d3e0a9e` Phase 8.3 — lifecycle service boundaries | `LifecycleGuard`, `GuardsLifecycleAttributes`; requisition status guarded; requester cannot self-approve; `lifecycleFixture()` test helper | 1,401 |
| 2 | `288ef03` Phase 8.3 — application lifecycle integrity | Application guard; `StageTransitionService::advance`; `ApplicationAssignmentService` (move, reassign); `ApplicationMovedToRequisition`; form locks | 1,413 |
| 3 | `f5c8f36` Phase 8.3 — interview lifecycle integrity | Interview guard; cancel/no-show through the service; `InterviewMarkedNoShow` + trigger; feedback lifecycle (migration), `InterviewFeedbackService`, `InterviewFeedbackPolicy` | 1,425 |
| 4 | `cab1951` Phase 8.3 — offer lifecycle integrity | Offer eligibility rule; guarded status and released terms; `offer_revisions` (migration) and revision flow; Offer audit with redaction; `OfferAccepted` after commit; in-transaction joining creation; sibling withdrawal | 1,437 |
| 5 | `3a83119` Phase 8.3 — joining and employee conversion integrity | Joining guard; `JoiningStatus::Cancelled`; `employees.convert` (grant migration); idempotent audited conversion; hierarchy cycle guard | 1,445 |
| 6 | `23c3956` Phase 8.3 — rejection and dropout cascades; after-commit events | `ApplicationClosureCascade`; all events after commit | 1,452 |
| 7 | `367a553` Phase 8.3 — idempotency and automation reliability | Atomic AI approval claim; automation job `uniqueFor` | 1,456 |
| 8 | `bf2c94f` Phase 8.3 — joining anchor for filled openings and Hiring Memory | Filled openings and Hiring Memory time to hire from the joining record | 1,459 |
| 9 | `e152e44` Phase 8.3 — queue and deployment reliability | Two-worker compose topology, retry_after, indexing jobs to the intelligence queue, runbook, `QueueTopologyTest` | 1,462 |
| 10 | `a97790d` Phase 8.3 — lifecycle audit | `lifecycle:audit` (read-only) | 1,466 |
| 11 | `68541f0` Phase 8.3 — outcome evaluation hardening | Chunk transactions + savepoints, eager loading, per-record failure isolation | 1,468 |
| 12 | `6903ff3` Phase 8.3 — tests, security and browser hardening | Architecture and security tests; smoke-found fixes; header grouping; offer page "Request revision" | 1,475 (serial and parallel) |
| 13 | Phase 8.3 — documentation and release freeze | This plan, `docs/phase-8-3-lifecycle-integrity.md`, `docs/phase-8-3-security-review.md`, backlog, `.ai/rules` | — |

## Migrations (all additive; 134 → 137)

- `2026_09_26_134408_add_lifecycle_to_interview_feedback_table`
  - Adds columns.
  - The unique key gains `version`. This is an index change; no data changes, and the migration's `down()` restores the original key.
- `2026_09_26_135649_create_offer_revisions_table`
- `2026_09_26_141200_grant_phase_eight_three_permissions` (data: `employees.convert` to VP HR and CHRO)

## Release notes for deploy

1. Back up, then run `php artisan migrate --force`.
2. **Deploy both queue workers** (`queue` and `queue-background`, or the two commands in the runbook §4). Set `DB_QUEUE_RETRY_AFTER=330`. Run `php artisan queue:restart`.
3. Run `php artisan lifecycle:audit` to see what older data contains. It is read-only. Warnings are expected for pre-8.3 history; repairing anything needs its own approval.
4. Tell users:
   - Released offers change only through **Request revision**.
   - Feedback is recorded against the assigned interviewer and locked once the interview is completed.
   - Rejecting or dropping an application now closes its open interviews, offers and joining.
   - Moving an application or reassigning its recruiter uses the **Reassign** actions.
   - Converting to an employee needs `employees.convert` (VP HR, CHRO).
   - "Filled" openings now count joined joining records, so numbers may fall where the stage said Joined without a joining.
