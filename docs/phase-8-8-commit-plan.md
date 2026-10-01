# Phase 8.8 Commit Plan

**Branch:** `feature/sep_25_hrm`. **Base:** `a98b0c2` (Phase 8.7 freeze).

This covers the Phase 8.8 **Authentication Foundation** freeze. Implemented: D8.8-036 (containment); D8.8-001 (the authentication foundation, with D8.8-003 limited to ending other candidate sessions after a password change); and the remediation the owner decided on 2026-10-01. Every other D8.8 decision remains unresolved.

**Git rules followed:** nothing pushed or deployed; no history rewritten, reset or squashed; the hotfix branch `hotfix/filament-delete-authorization` (`2fab3fd`) was not touched.

## Commits

| # | Commit | Decision | Content |
|---|---|---|---|
| 1 | `55fefd3` | D8.8-036 | SEC-88-01 / SEC-88-09 containment: held career submissions for strongly matching contacts, neutral response page, 30 security tests |
| 2 | `9315879` | D8.8-001 | candidate session context and separate cookie; candidate actor attribution for signed links; other sessions ended after a password change or reset; email step-up capability; authentication audit; redaction; 38 security tests; SEC-88-09 test made independent of test order (Livewire static state) |
| 3 | `9bdd6bc` | D8.8-001 | fix found in verification: the failed sign-in audit ran after the guard's time box, so an existing email answered measurably slower than an unknown one; now time-boxed (50 ms); 1 test |
| 4 | `460c394` | — | test-only: `OutcomeReobservationTest` (Phase 8.5) errored about 1 run in 55, because the employee's random joining date could fall after the separation date the test records; the joining date is now pinned. Found by the final parallel run |
| 5 | `05a9fd3` | owner decisions 2026-10-01 | remediation of the A items: SEC-88-04, 03 (+24), 12, 13, 15, 06, 17, 11; 37 tests; routes 240 → 239 |
| 6 | (this commit) | — | documentation: decision register, authentication foundation, implementation, security review (30-finding reconciliation), performance, commit plan, freeze-gate record, `.ai/rules` |

## Browser regression matrix (final application code `05a9fd3`)

Real Chromium; each phase runs on its own throwaway database, dropped, re-migrated and re-seeded with its own seed. Runs use the Phase 8.7 worker topology (database queue, three workers) unless noted. This final run was on `05a9fd3`; earlier runs are summarised below the table.

| Phase | Database | Mode | Result |
|---|---|---|---|
| 6 | `hrms_p6_smoke` | workers | **24/24**, no problems |
| 7 | `hrms_p7_smoke` | workers, AI keys empty | **20/20**, no problems |
| 8.1 | `hrms_p81_smoke` | workers | **12/12**, no problems |
| 8.2 | `hrms_p82_smoke` | workers | **20/20**, no problems |
| 8.3 | `hrms_p83_smoke` | workers | **17/17**, no problems |
| 8.4 | `hrms_p84_smoke` | MFA enforced, database sessions, workers | **19/19**; the known `showModal` console message only |
| 8.5 | `hrms_p85_smoke` | workers | **15/15**; transient `ERR_CONNECTION_CLOSED` console messages, not reproducible (rerun clean) |
| 8.6 | `hrms_p86_smoke` | sync queue, as designed | **16/16**, no problems |
| 8.7 | `hrms_p87_smoke` | sync queue | **15/15**, no problems |
| 8.8 containment | `hrms_p88c_smoke` | sync queue | **7/7**, no problems |
| 8.8 authentication | `hrms_p88a_smoke` | database sessions and cache, sync queue | **19/19**, no problems |

Total **184/184**.

Earlier runs:
- after `9315879`: 184/184;
- after `9bdd6bc`: 184/184. 8.3 stopped once on a UI-timing overlay in that matrix, then passed 17/17 in three isolated reruns.

## Deployment

See `phase-8-8-authentication-foundation.md` §8 (code-only; candidates sign in once more after deployment) and `phase-8-8-implementation.md` §7. Nothing is deployed. The production hotfix `2fab3fd` remains unmerged and undeployed.
