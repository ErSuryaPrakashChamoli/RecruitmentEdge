# Phase 8.6 Freeze: Data Governance, Master Data & Configuration Integrity

**Status: COMPLETE — frozen.** Phase 8.7 implementation is not started.

| | |
|---|---|
| Branch | `feature/sep_25_hrm` |
| Baseline | `dcff76e` |
| Frozen at | the commit that adds this document (the documentation-and-freeze commit following `14e7416`) |

## Release gate

| Gate | Result |
|---|---|
| Migrations | 150 → **158**, all additive. MySQL 8.4 `migrate:fresh --seed` OK; rollback of the eight 8.6 migrations and re-migrate OK. |
| Routes | 237 → **235**: raw settings create/edit removed. No duplicates. |
| Tests, parallel (4 processes) | **1,798 passed**, 19,922 assertions |
| Tests, serial | **1,798 passed**, 19,922 assertions |
| Phase 8.6 tests | 77 in `tests/Feature/Governance` (11 files), plus `PolicyCoverageTest` and `StrictAuthorizationTest` |
| Mutation checks | **23 of 23** safeguard mutations caught (below) |
| Strict authorization | On for local and test; the whole suite runs under it. Production fails closed. |
| Browser, Phase 8.6 | **16/16** (real Chromium, throwaway database) |
| Browser regression | Phase 6: 24/24 · Phase 7: 20/20 · Phase 8.1: 12/12 · Phase 8.2: 20/20 · Phase 8.3: 17/17 · Phase 8.4: 19/19 (MFA enforced, database sessions) · Phase 8.5: 15/15 |
| Governance audit | Fresh seeded install: 0 errors, 0 warnings. Dev database: 0 errors, 1 warning (one pre-8.6 incentive calculation whose slab had been deleted — reported, not repaired). |
| Performance | See `phase-8-6-performance.md`. The SLA metric's as-of targets cost nothing measurable after optimisation; governance audit 150 ms at 100k. |
| Security | See `phase-8-6-security-review.md`. No open critical or high finding on this branch. Production hotfix pending (below). |
| Pint | clean |
| `npm run build` | OK |
| `optimize:clear` | OK |
| Static analysis | Not installed (not added, as instructed) |
| `git diff --check` | clean |

**Phase 6 browser regression note:** the smoke script was updated to give the reasons that D8.6-021 now requires when activating and pausing a rule. The seed fixture was updated the same way.

## Failure modes → regression tests

| # | Failure mode | Test |
|---|---|---|
| 1 | Master-data force deletion destroys dependencies | `MasterDataLifecycleTest` "FAILURE 1" (+ every governed type) |
| 2 | Frozen records change because master data changes | `InterviewerAndSnapshotGovernanceTest` "FAILURE 2" |
| 3 | Issued offer letter changes after template edit | `OfferLetterIntegrityTest` "FAILURE 3" (+ browser 7: byte-identical download) |
| 4 | Historical SLA compliance changes after a target change | `SettingsHistoryTest` "FAILURE 4" |
| 5 | Pending incentive changes because a slab changes | `IncentivePricingIntegrityTest` "FAILURE 5" |
| 6 | Incentive history shows live configuration | `IncentivePricingIntegrityTest` "FAILURE 6" |
| 7 | Pipeline re-application silently changes history | `PipelineTemplateGovernanceTest` "FAILURE 7" |
| 8 | Configuration changes without audit or reason | `SettingsHistoryTest` "FAILURE 8" (+ automation, master data) |
| 9 | Filament action available because a policy method is missing | `StrictAuthorizationTest`, `PolicyCoverageTest` |
| 10 | Configuration authority bypasses the lifecycle | separation of duties, re-apply permission, master-data authority tests |

## Mutation checks (safeguard removed → test must fail)

All 23 were caught:

1. force-delete guard
2. in-use check
3. active-master-data guard
4. frozen snapshot names
5. stored letter served
6. as-of SLA target (re-checked after the optimisation)
7. slab lock
8. priced display
9. remap history
10. setting reason
11. fail-closed gate
12. joining create rule
13. separation of duties
14. re-apply permission
15. `restored` audit action
16. explicit-payload redaction
17. interviewer list
18. template archive guard
19. rule-terms lock
20. target overlap
21. freeze catch-up
22. config fingerprint key set (caught by the pin test)
23. governance orphan-scope check

## Hotfix (D8.6-030)

**Status: deployment procedure ready — NOT deployed, NOT pushed.**

- `hotfix/filament-delete-authorization` @ `2fab3fd` sits on `main` @ `9cba8e3` (unchanged) and passes 644/644 on its own worktree (re-verified this phase).
- Phase 8.6 carries equivalent and stronger protection.
- Recommended before deploying: add the manual-joining `create()` fix (SEC-86-I-01), also missing on `main`.
- The procedure is in `phase-8-6-implementation.md` §7.
- Production deployment remains a **manual release action** for the product owner.

## Known limitations

See `phase-8-6-implementation.md` §6. In short:
- **Pre-8.6 history, not reconstructed:** letters, setting changes, template versions, pricing snapshots and snapshot names from before 8.6 are not back-filled. Each is labelled or reported, never invented.
- **Database foreign-key actions unchanged** (deferred).
- **Metric cache:** up to 600 s of staleness for the current period after a target change.

## Backlog

`docs/backlog.md` → P86-BACKLOG-001 … 010 (P86-BACKLOG-003 superseded by D8.7-014; P86-BACKLOG-007 is the pending production hotfix).

## Phase 8.7 handoff

| | |
|---|---|
| Phase 8.7 baseline | the Phase 8.6 freeze commit |
| Phase 8.7 documents | `phase-8-7-discovery.md`, `-security-review.md`, `-performance.md`, `-decision-record.md` — committed in `88fbcf6`, unchanged by 8.6 |
| Shared areas | `audit_logs` now has `reason` (8.7 adds actor columns alongside); communication messages link template versions (8.7 D8.7-008 builds on it); automation versions include owner and priority (8.7 D8.7-009 builds on them) |
| Phase 8.7 implementation | **NOT STARTED** |
