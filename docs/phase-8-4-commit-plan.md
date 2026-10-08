# Phase 8.4: Commit Plan

Phase 8.4 was committed incrementally on `feature/sep_25_hrm`, on top of the Phase 8.3 release (`6109823da022bccabe23207968afaa0521b6d4ff`). **Nothing has been pushed.**

**State of each commit:**
- Built and passed the full suite in parallel. The count shown in the table is from that commit.
- Pint-clean and scanned for secrets.
- Migrations are additive.

**Deviations from the suggested sequence:**
- **Role model and key in commit 1.** The immutable role key (`App\Models\Role`) went into the foundation, because last-CHRO protection in the access service needs it.
- **Access review with the admin UI.** The Access Review, admin UI and reconciliation/audit commands share one commit (10).
- **Fingerprint narrowed in commit 12.** The browser smoke showed that including the whole visible team invalidated unrelated actions when someone was hired into the team, so the AI authority fingerprint was narrowed there.

## Commits

| # | Commit | Contents | Suite |
|---|---|---|---|
| 1 | `8a4dfc4` Phase 8.4 — access-state foundation | Access state + gate, `StaffAccessService`, session epoch, `EnforceStaffAccess`, sign-in audit, `App\Models\Role` with key, 8.4 permissions | 1,490 |
| 2 | `04723ef` Phase 8.4 — separation revocation and employment lifecycle | `EmployeeStatus::Separated`, `EmployeeLifecycleService`, effective separation, `identity:enforce-separations`, deactivate / reactivate, Outcome compatibility | 1,508 |
| 3 | `d9b1147` Phase 8.4 — authority guardrails | `RoleAssignmentService`, protected roles, self-escalation rules, employee link guard, Users/Roles forms through services | 1,528 |
| 4 | `b527c6e` Phase 8.4 — hierarchy integrity | `HierarchyIntegrityService`, scoped/locked reassignment, delete/restore rules, deleted-recruiter fix | 1,540 |
| 5 | `db33ff9` Phase 8.4 — AI identity security | Locked conversation, owner-scoped lookup, requester/expiry/fingerprint, invalidation, expiry sweep | 1,552 |
| 6 | `4071ae3` Phase 8.4 — credentials, sessions and identity audit | Password policy, staff reset, email-change verification, sign out everywhere, lockout, auth audit, request id | 1,568 |
| 7 | `bb23533` Phase 8.4 — MFA | Authenticator MFA, required for privileged users, audited lifecycle | 1,577 |
| 8 | `fc15bf1` Phase 8.4 — provisioning, rehire and separation cancellation | Conversion provisions the identity, invitation, portal retirement, rehire, cancellation | 1,590 |
| 9 | `e10a045` Phase 8.4 — ownership handoff, automation authority and notification routing | Owner re-check before runs, handoff, reachability, alert routing | 1,601 |
| 10 | `c5f3db0` Phase 8.4 — access review, admin UI, reconciliation and identity audit | Access Review, user access actions, `identity:reconcile-access`, `identity:audit` | 1,608 |
| 11 | `a6ae128` Phase 8.4 — metric fixes (D12) | Three confirmed metric defects | 1,611 |
| 12 | `c229f9d` Phase 8.4 — tests, security, browser and performance hardening | Architecture tests, mutation checks, fingerprint narrowed, smokes, benchmark | 1,618 (serial and parallel) |
| 13 | Phase 8.4 — documentation and release freeze | This plan, `docs/phase-8-4-access-identity-lifecycle.md`, `docs/phase-8-4-security-review.md`, backlog, `.ai/rules` | — |

## Existing tests changed (disclosed)

- **Direct writes of now-guarded attributes use `lifecycleFixture()`:** employee status and reporting line, in 8 files. This is the Phase 8.3 convention.
- **Conversion tests name the manager explicitly:** conversion now requires one.
- **AI approval tests make the approver the requester:** only the requester may approve now.
- **The 8.2 separation audit test expects the new `separation_effective` row.**
- **`AutomationTimeBasedTest`** (the daily-cap test) now starts at 09:00. It failed when run within two hours of midnight — a pre-existing time-of-day dependence.
- **`AuditableTest`** records role changes against `App\Models\Role`.

## Validation

- **Test suite:** 1,618 tests and 17,281 assertions, serial and parallel (`--processes=4`). The Phase 8.3 baseline was 1,475 tests and 11,340 assertions, so 8.4 adds 143 tests.
- **Browser smokes** (Playwright, outside the repository, throwaway MySQL databases, fake unreachable provider):

| Suite | Result | Note |
|---|---|---|
| Phase 8.4 | 19/19 | |
| Phase 8.3 | 17/17 | Seed records the AI proposal's requester, as 8.4 requires |
| Phase 8.2 | 20/20 | |
| Phase 8.1 | 12/12 | Same seed adaptation |
| Phase 7 | 20/20 | Run in its original no-provider configuration |
| Phase 6 | 24/24 | |

- The one browser console message in the 8.4 smoke is Livewire's error dialog after the deliberately refused locked-property update.
- **Benchmark** (MySQL, 3,011 employees in a CHRO → 10 VP → 100 manager → 2,900 recruiter tree, all with logins; 2,900 applications; 500 pending AI actions):

| Operation | Time | Queries |
|---|---|---|
| Login gate (`canAccessPanel`) | 1.1 ms | 2 |
| Authorization (`can()`, fresh user / repeated) | 2.6 ms / 0.26 ms | 3 / 0 |
| Access Review page of 50 (CHRO over 3,011 / VP over ~300) | 72 ms / 46 ms | 11 / 12 (constant) |
| Hierarchy tree over 3,011 | 141 ms | 5 |
| Scope (`visibleEmployeeIdsFor`, VP) | 1.2 ms | 1 |
| AI authority fingerprint | 3.7 ms | 6 |
| AI expiry sweep (250 of 500 expired) | 2.2 s | ≈7 per expired action |
| Separation recorded + applied (revoke + handoff) | 56 ms | 66 |
| Bulk revocation (200, one by one, handoff included) | 41 ms each | 45 each |
| Handoff of a manager with 29 reports | 84 ms | 80 |
| Conversion with provisioning | 475 ms | 48 (mostly password hashing) |
| Rehire | 257 ms | 52 |

## Migrations (all additive; 137 → 145)

See §17 of `docs/phase-8-4-access-identity-lifecycle.md`.

## Release notes for deploy

Follow §18 of `docs/phase-8-4-access-identity-lifecycle.md`:
- back up;
- migrate;
- deploy and restart both workers;
- dry-run, then execute, `identity:reconcile-access`;
- run `identity:audit` and `lifecycle:audit`.

**Tell users:**
- Privileged roles enrol in MFA at their next sign-in.
- Leaving the company ends access the day after the last working day.
- Departed people's open work is handed to their manager.
- Conversion now creates the login and asks for the manager.
- Access is managed in Administration → Access Review.
