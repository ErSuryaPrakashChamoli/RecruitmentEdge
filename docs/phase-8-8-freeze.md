# Phase 8.8 Authentication Foundation — Freeze

**Status: FROZEN** at the commit that adds this document. Every freeze condition is met: decisions recorded, A items implemented, suites, mutations, browser matrix, performance, migrations, routes, build and Pint verified on the final application commit, security gate passed, working tree clean. Nothing pushed or deployed. **Phase 8.9 is not started.**

This freeze covers the approved **Authentication Foundation (D8.8-001)**, the SEC-88-01 / SEC-88-09 containment (D8.8-036), and the remediation the project owner decided on 2026-10-01. It does **not** implement the broader Phase 8.8 candidate experience, API, import/export or retention work. Those decisions stay open, as listed below.

| | |
|---|---|
| Branch | `feature/sep_25_hrm` |
| Baseline | `a98b0c2` (Phase 8.7 freeze) |
| Final application commit | `05a9fd3` |
| Final HEAD | the commit that adds this document (documentation only, on top of `05a9fd3`) |
| Pushed / deployed | **no / no** |

## 1. Decisions

| Decision | Status |
|---|---|
| D8.8-001 candidate authentication model | approved 2026-09-28 (option c); implemented `9315879`, `9bdd6bc` |
| D8.8-036 containment of SEC-88-01 | approved; implemented `55fefd3` |
| Owner dispositions of 2026-10-01 | recorded verbatim in `phase-8-8-decision-record.md` → "Owner decisions" (SEC-88-02 C; 03, 04, 06, 11, 12, 13, 15, 17 A; 05, 07, 14 B; 10, 16 C; Low and Informational C) |
| D8.8-037 recruiter visibility of portal links | approved: the link is never shown to staff |
| D8.8-RETENTION-001 | R-14 decided: SEC-88-02 deferred. R-1–R-13 open |
| D8.8-EXPORT-001 | partly approved: 10,000-row cap, audit, pay gating, 24-hour download, staff-access + MFA, formula neutralisation. X-1, 2, 6, 7, 8, 10 open; X-12 deferred |
| Other D8.8 decisions | **not approved**, unchanged (register in `phase-8-8-decision-record.md`) |

No recommendation was converted into an approval. Retention periods, legal holds, erasure, consent semantics, API scope, webhooks, document retention and import policy are **not** decided and were not invented.

## 2. Implemented scope

| Commit | Content |
|---|---|
| `55fefd3` | SEC-88-01 / 09 containment: strongly matching career submissions are held; one neutral response page |
| `9315879` | D8.8-001: separate candidate session cookie and guard; candidate actor on signed links; other sessions ended after a password change or reset; email step-up capability (10 min, 5 attempts, newest code only, HMAC-only, rate-limited; no action requires it yet); authentication audit; redaction |
| `9bdd6bc` | the failed sign-in audit is time-boxed, so existing and unknown emails answer in the same time |
| `460c394` | test-only: a flaky Phase 8.5 fixture pinned |
| `05a9fd3` | the owner-decided A items: SEC-88-04 (link never shown to staff), 03 + 24 (export cap, audit, owner-only 24-hour download behind staff-access and MFA), 12 (formula neutralisation), 13 (download audit), 15 (pay gating), 06 (upload path guard, types, sizes), 17 (private files only through `files.private`), 11 (security headers) |

Details: `phase-8-8-authentication-foundation.md`, `phase-8-8-implementation.md` (Parts 1–3), `phase-8-8-export-governance-decision.md` §7.

## 3. Security disposition

Full table: `phase-8-8-security-review.md` → "Final dispositions (2026-10-01)".

| Severity | Closed | Accepted / deferred by owner decision | Undispositioned |
|---|---|---|---|
| Critical | — | — | **0** |
| High | SEC-88-01, SEC-88-03 | SEC-88-02 (C) | **0** |
| Medium | SEC-88-04, 06 (hardening), 08, 09, 11, 12, 13, 15, 17 | SEC-88-05, 07, 14 (B); SEC-88-10, 16 (C) | **0** |
| Low | SEC-88-19, 24 | SEC-88-18, 20–23, 25–27 (C) | **0** |
| Informational | SEC-88-29 | SEC-88-28 (C); SEC-88-30 not applicable | **0** |

**Freeze security gate:**
- **Critical:** 0.
- **High:** SEC-88-03 fixed and verified. SEC-88-02 explicitly deferred by an owner decision that records its ID (D8.8-RETENTION-001 R-14), rationale, residual risk, target phase, and that it does not block this freeze.
- **Medium:** every finding is fixed, or explicitly accepted / deferred.

**Passed.**

### Residual accepted / deferred risks (not hidden)

| Finding | Residual risk | Target |
|---|---|---|
| SEC-88-02 (High) | All candidate data, documents, communications, audit (with personal data and salary), AI records and stored export files are kept indefinitely; no erasure, anonymization, legal hold or deletion-request path | dedicated data-governance / retention phase |
| SEC-88-03 parts not decided | stored export files kept indefinitely; one permission covers all exports; no rate limit, export reason or approval | D8.8-EXPORT-001 X-1, 2, 6, 7, 8, 10 |
| SEC-88-04 remainder | old address not notified when a portal email changes | D8.8-004 |
| SEC-88-05 | candidate identity and salary in plaintext audit values | 8.8C / 8.8F streams |
| SEC-88-06 remainder | no malware scanning of uploads | D8.8-029 |
| SEC-88-07 | forwarded scheduling links remain usable and are not revocable | D8.8-023 / 024 |
| SEC-88-10 | correct only while Apache serves production directly — re-open if a proxy / CDN is added | 8.8A stream |
| SEC-88-14 | weak, reversible consent evidence | D8.8-025 / 026 |
| SEC-88-16 | a candidate export can still be uploaded to the AI knowledge base by `ai.manage` holders | E-10 |
| Low / Info (SEC-88-18, 20–23, 25–28) | as listed in the security review | 8.8A–G streams |

## 4. Tests (final application commit `05a9fd3`)

| Suite | Result |
|---|---|
| Full, parallel (4 processes) | **1,979 passed**, 21,010 assertions, 0 failed, 0 skipped (250 s) |
| Full, serial | **1,979 passed**, 21,010 assertions, 0 failed, 0 skipped (593 s) |
| Phase 8.8 security (`tests/Feature/Security`) | 113 passed (585 assertions) |
| Candidate portal | 32 passed |
| Career site + containment | 52 passed |
| Authentication (candidate + staff, incl. `tests/Feature/Identity`) | 176 passed |
| Architecture | 30 passed |
| Queue / security regression (`tests/Feature/Reliability`, Phase 8.7) | 69 passed |

Test growth since the 8.7 freeze: 1,873 → 1,979 tests. That is +30 containment, +39 authentication foundation and +37 remediation tests.

## 5. Mutation gate (final application commit `05a9fd3`)

There were **42 security mutations, all caught**. Each was applied alone; the security, portal and payload-privacy tests (151) were run; then it was reverted with `git checkout` and the tree verified clean. 7b was re-run once in its valid form (see `phase-8-8-authentication-foundation.md` §9).

| # | Control removed | Observed | Result |
|---|---|---|---|
| 1 | signed-link actions run as the candidate | 4 failed | PASS |
| 2 | candidate / staff session separation | 3 failed | PASS |
| 3a–c | stale session accepted; remember token not replaced; fingerprint not stored | 2 / 1 / 1 failed | PASS |
| 4a–e | OTP: not consumed; 6th attempt; plaintext; purpose; step-up gate | 1 / 1 / 1 / 1 / 2 failed | PASS |
| 5a–b | signed-link authorization (scheduling; password set) | 3 / 2 failed | PASS |
| 6a–b | candidate ownership (application; invitation / booking) | 2 / 2 failed | PASS |
| 7a–d | auth audit: sign-in not audited; email in audit; password in audit; time box removed | 1 / 1 / 1 / 1 failed | PASS |
| 8 | SEC-88-04: link shown to staff | 1 failed | PASS |
| 9a–f | SEC-88-03: no row cap; request not audited; any downloader; no 24 h window; no staff-access / MFA; download not audited | 1 / 1 / 1 / 1 / 1 / 1 failed | PASS |
| 10a–b | SEC-88-12: export formulas; report formulas | 1 / 1 failed | PASS |
| 11a–d | SEC-88-15: offer letter; incentive export; period statement; calculation statement without `compensation.view` | 1 / 1 / 1 / 1 failed | PASS |
| 12a–c | SEC-88-13: offer letter, statement, report downloads not audited | 1 / 1 / 1 failed | PASS |
| 13a–c | SEC-88-06: path tampering; document types / size; resume size | 1 / 1 / 1 failed | PASS |
| 14a–c | SEC-88-17: open `storage/{path}`; no user binding; no audit | 1 / 1 / 1 failed | PASS |
| 15a–b | SEC-88-11: headers removed; framing allowed | 6 / 5 failed | PASS |

## 6. Browser matrix (final application code)

Real Chromium; each phase runs on its own throwaway MySQL 8.4 database, dropped, re-migrated and re-seeded with its own seed. Runs use the Phase 8.7 worker topology unless noted. The run was against `05a9fd3`.

| Phase | Result |
|---|---|
| 6 | **24/24**, no problems |
| 7 | **20/20**, no problems (AI keys empty) |
| 8.1 | **12/12**, no problems |
| 8.2 | **20/20**, no problems |
| 8.3 | **17/17**, no problems |
| 8.4 (MFA enforced, database sessions) | **19/19**; only the known `showModal` console message, logged since the 8.4 baseline |
| 8.5 | **15/15**; 14 transient `net::ERR_CONNECTION_CLOSED` console messages, with no failed check, no HTTP 5xx and no page error. An immediate rerun logging every failed request was 15/15 with no failed request and no console error — not reproducible |
| 8.6 (sync queue) | **16/16**, no problems |
| 8.7 | **15/15**, no problems |
| 8.8 containment | **7/7**, no problems |
| 8.8 authentication | **19/19**, no problems |

**Total 184/184.** Earlier runs of the same harness: 184/184 after `9315879`. After `9bdd6bc`, 8.3 stopped once on a UI-timing overlay; it then passed 17/17 in three isolated reruns, and passed in this final run inside the matrix.

## 7. Performance

`phase-8-8-performance.md` → "Final measurements on the frozen code". Measured, not optimised:
- Authentication, compared with `55fefd3`: one audit row on sign-in, password set and step-up; failed sign-in +50 ms (the deliberate time box); a stale session is ended (302) instead of continuing; dashboards, signed-link pages and staff requests unchanged.
- Remediation paths, compared with `460c394`: the security headers cost nothing measurable; export download +1 query (policy and audit); report export +1 audit row; a private file opens in about 3.8 ms with 2 queries.
- No regression found.

## 8. Migrations, routes, build

| Check | Result |
|---|---|
| Migrations | **160** (unchanged since 8.7; Phase 8.8 adds none — rollback/re-migrate not applicable); **0 pending** on the development database; `migrate:fresh --seed` succeeded on all 11 throwaway MySQL 8.4 smoke databases during the matrix |
| Routes | 237 (8.7) → 240 (`9315879`: three step-up routes) → **239** (`05a9fd3`: `GET`/`PUT storage/{path}` removed, `GET files/private` added) |
| `npm run build` | OK |
| Pint | passed |
| `git diff --check` | clean |
| `php artisan optimize` (config / route / view cache) | OK, then cleared |
| Static analysis | not installed in the project — **not run** |
| Secrets | none in the diff; `.env` not committed |
| Debug / temporary artifacts | none; disposable benchmark probes and worktrees live outside the repository |

## 9. Known limitations (behaviour of the approved model)

- No route requires step-up yet; it is a capability for future sensitive actions.
- There is no in-portal "change password" screen; the emailed set-password link is the only path, and session invalidation applies there.
- After deployment every candidate signs in once more (the cookie name changes). Bookmarked `/storage/...` preview URLs stop working, and previews regenerate on open.
- Exports older than 24 hours cannot be downloaded (run them again). Exports above 10,000 rows are refused (filter first).
- A failed portal sign-in takes about 50 ms longer, from the time box.
- Step-up codes live in the shared cache; clearing it discards outstanding codes.
- Custom roles without `compensation.view` lose offer-letter downloads, the incentive export and other people's statements.
- Observation from verification (development environment only): `queue:health-check` reports a 17-day-old job waiting on the dev database's `default` queue. Local state; not changed.

## 10. Backlog

| ID | Item | Depends on |
|---|---|---|
| P88-BACKLOG-001 | SEC-88-02 retention, erasure, anonymization, legal hold; export-file expiry | D8.8-RETENTION-001 R-1–R-13 (Legal) |
| P88-BACKLOG-002 | Export governance remainder (role split, organisation-wide restriction, reason, approval, rate limits) | D8.8-EXPORT-001 X-1, 2, 6, 7, 10 |
| P88-BACKLOG-003 | Deferred Medium: SEC-88-05, 07, 10 (if the topology changes), 14, 16; scanning (06); email-change notice (04) | as in §3 |
| P88-BACKLOG-004 | Deferred Low / Informational: SEC-88-18, 20–23, 25–28 | as in the security review |
| P88-BACKLOG-005 | Engineering defaults not delivered: E-01, E-02, E-07 (request-id half), E-09, E-10, E-11, E-12, E-13, E-14 | owner approval |
| P88-BACKLOG-006 | Performance PF-88-01–12 | Phase 8.9 discovery |
| P88-BACKLOG-007 | Triage: staff profile photos on the public disk are readable by URL (noted in the export governance package) | Security |

## 11. Hotfix dependency

`hotfix/filament-delete-authorization` @ `2fab3fd`:
- not touched, not merged into `main` or this branch, on no remote, **not deployed**;
- it **still lacks SEC-86-I-01** (manual joining `create()` authorization): `CandidateJoiningPolicy` there has no `create()`, while on this branch it requires `joining.confirm`;
- it must not be deployed without that fix.

## 12. Production status and deployment prerequisites

**Not deployed. Not pushed.** `main` (`9cba8e3`, the production line) contains none of Phases 8.1–8.8. Freezing is not a production-readiness claim: a release needs its own decision. Prerequisites for a future release of this branch:
1. Decide the hotfix question above.
2. Back up the database.
3. Deploy code; `php artisan optimize:clear && php artisan optimize`; `php artisan queue:restart`. There is no migration.
4. Set `SESSION_SECURE_COOKIE=true` behind HTTPS, and keep a shared `CACHE_STORE`.
5. Confirm the production `.env` is not using the development defaults (SEC-88-27).
6. Confirm there is still no proxy / CDN in front of Apache (SEC-88-10).
7. Tell staff that exports are capped and expire after 24 hours, and that custom roles need `compensation.view` for pay documents.

## 13. Phase 8.9 handoff

Phase 8.9 (Enterprise Scale, Performance, Observability & Operational Readiness) is **not started**.

Inputs it should take from here:
- PF-88-01–12;
- the open D8.8 decisions, especially retention (which affects data growth);
- the export cap;
- the shared `sessions` table;
- the new audit volume from authentication and download events.

Await explicit Phase 8.9 instruction.
