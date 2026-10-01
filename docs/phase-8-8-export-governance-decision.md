# Phase 8.8 Export Governance — Decision Package (D8.8-EXPORT-001)

**Status: PARTLY DECIDED (2026-10-01) — SEC-88-03 = A (fix before freeze), implemented in `05a9fd3`.** The decided parameters are below; everything else in §4 remains open. §2–§3 describe the export paths **as they were before the fix** (baseline `460c394`); §7 records what changed.

## Decision recorded (2026-10-01)

Project owner, working session of 2026-10-01 (recorded verbatim in `phase-8-8-decision-record.md` → "Owner decisions"):

| Area | Decision |
|---|---|
| X-13 freeze treatment | **SEC-88-03 → A, fix before freeze** |
| X-3 maximum volume | **10,000 rows per export** |
| X-5 audit | every export request (started or refused) and every download; also offer-letter, statement, report and document downloads (SEC-88-13 → A) |
| X-4 sensitive fields | offer letters, the incentive export and statements need `compensation.view`; a user's own statement is exempt (SEC-88-15 → A) |
| X-9 download controls | download link valid **24 hours**; download route behind the panel's staff-access and MFA checks (SEC-88-24); private files only to the signed-in staff user a link was issued to (SEC-88-17 → A) |
| X-11 formula neutralisation | required everywhere (SEC-88-12 → A) |
| X-12 AI knowledge uploads | **deferred** (SEC-88-16 → C; existing control: `ai.manage` + no-personal-data declaration) |
| X-8 stored-file expiry | **not chosen** — a retention period (D8.8-016, Legal), deferred with SEC-88-02 |
| X-1, X-2, X-6, X-7, X-10 | **not decided** — no role split, organisation-wide restriction, export reason, approval step or rate limit |


It addresses **SEC-88-03 (High)** — "bulk export of candidate contact data by every staff role, uncapped, unaudited, kept forever". It also covers the related findings:
- SEC-88-12 (formula injection);
- SEC-88-13 (export and download audit);
- SEC-88-15 (compensation gating);
- SEC-88-24 (download route middleware);
- SEC-88-17 (signed file URLs);
- SEC-88-16 (exports fed to the AI knowledge base).

It bundles decisions D8.8-016 (export retention), D8.8-017 (bulk limits) and D8.8-030 (export and download authorization).

**Baseline:** `460c394`. The inventory is read-only; the key claims were re-checked in code.

## 1. Seeded permissions that govern exports

`chro` holds every permission (`'*'`) and is the only role with `hierarchy.view-all` (the whole organisation). Source: `database/seeders/RolePermissionSeeder.php`.

| Permission | Roles holding it |
|---|---|
| `reports.export` | chro, vp_hr, manager, assistant_manager, recruiter (not `employee`) |
| `compensation.view` | chro, vp_hr, manager, assistant_manager, recruiter — every staff role, so today it restricts no one |
| `incentives.view` | chro, vp_hr, manager, assistant_manager, recruiter |
| `incentives.approve` | chro, vp_hr |
| `settings.manage` | chro |

A hidden Filament action cannot be mounted or run, so `->visible()` checks are enforced on the server.

## 2. Current export and download capabilities

Abbreviations:
- **Scope:** "hierarchy" = limited to records in the user's hierarchy (all for chro).
- **Expiry:** whether stored files are ever removed.
- PD = personal data; Pay = compensation.

### 2.1 Filament table exports (queued CSV + XLSX)

These seven run through the same mechanics:
- a header action on a list table, gated by `reports.export` plus the page's `viewAny` policy;
- the query is the resource's scoped query plus the user's filters, fixed when requested;
- every column is on by default (the user may untick columns);
- **no audit, no rate limit, no row cap** (`maxRows` never set);
- files under `filament_exports/{id}/` on the private `local` disk;
- queued on the `default` queue as the requesting user;
- downloadable **by the owner only, any number of times, through a signed link that never expires**;
- **files and rows are never removed.** The `Export` model is `Prunable` without a query, nothing schedules cleanup, and deleting a user removes the row but leaves the files;
- **formula neutralisation is off.** Filament's `preventFormulaInjection()` is opt-in and unused, and XLSX turns a leading `=` into a live formula.

| # | Export | Who (beyond `reports.export`) | Data | PD | Pay | Documents | Scope |
|---|---|---|---|---|---|---|---|
| A1 | Candidates (`CandidateExporter`) | `candidates.viewAny` | candidate code, **full name, mobile, email**, source, experience, sourced date | **yes (contact)** | no | no | hierarchy (`Candidate::visibleTo`) |
| A2 | Applications | `candidates.viewAny` | application code, candidate name, requisition, recruiter, stage, status, priority, dates | name | no | no | hierarchy |
| A3 | Joinings | `joining.confirm` | candidate name, requisition, recruiter, expected and actual joining date, status, document status label | name | no | no (status only) | hierarchy |
| A4 | Interviews | `interviews.manage` | candidate name, application code, round, interviewer, time, mode, status, result (no feedback text) | name | no | no | hierarchy |
| A5 | Offers | `offers.manage` | offer code, candidate name, designation, **offered CTC**, dates, status | name | **CTC — masked as "Restricted" without `compensation.view`** (the only gated export) | no | hierarchy |
| A6 | Recruiter incentive calculations | `incentives.view` | recruiter, candidate name, rule, period, achievement, **amount, effective amount**, status | name | **yes — not gated** | no | hierarchy |
| A7 | Recruiter performance snapshots | `performance.view` | recruiter, period, score, target / actual per metric | staff performance | no | no | hierarchy |

### 2.2 Other exports and downloads

| # | Path | Who | Data | PD / Pay / Docs | Scope | Audited | Rate limit | Row cap | Stored / repeat / expiry |
|---|---|---|---|---|---|---|---|---|---|
| B | `GET filament/exports/{export}/download` | the export's owner (no export policy exists) | the A1–A7 files | as A1–A7 | owner only | no | no | — | unlimited re-downloads; the link never expires. **Runs under `web` only — not the panel's staff-access and MFA middleware** (SEC-88-24); a separation not yet applied is not caught on this route |
| C | Recruitment report CSVs (funnel, source ROI, vacancy ageing) | `performance.view` (page) + `reports.export` (each method) | aggregates, company spend, requisition ageing | no PD / no pay | hierarchy | no | no | naturally bounded | streamed, not stored; raw `fputcsv` (no formula neutralisation) |
| D1 | Incentive statement, one calculation (PDF) | `reports.export` + record `view` (`incentives.view` + hierarchy) | recruiter, candidate name, rule, amounts, adjustments, payments | name; **pay — not gated** | hierarchy | no | no | one record | streamed |
| D2 | Period statement for any recruiter in scope (PDF) | `reports.export` or `incentives.approve`, re-checked on the server | totals, every calculation with candidate names, payments | name; **pay — not gated** | hierarchy | no | no | one person × month | streamed |
| D3 | Own incentive statement (PDF) | `incentives.view` + linked employee | as D2, own data | own pay | own | no | no | own | streamed |
| E | Offer letter PDF | offer `view` (`offers.manage` + hierarchy) | candidate name, role, dates, **fixed, variable, bonus and total CTC**; templates can also merge email and mobile | PD + **pay — not gated by `compensation.view`** (SEC-88-15) | hierarchy | **download not audited** (issuance is) | no | one offer | issued PDFs kept immutably under `offer-letters/`; unlimited re-downloads |
| F | Offer letter Word template | `settings.manage` (chro) | template wording | none | — | no | no | — | private disk |
| G | Interviewer import template | `settings.manage` (chro) | column headings only | none | — | no | no | — | generated |
| H | Candidate documents and resumes (no download button) | opening an edit form with `candidates.update`, document, joining or referral policies | CVs, ID / address proofs, salary slips, bank details | **documents, heavy PD** | hierarchy (for the form) | no | no | one file | Filament creates a signed `GET /storage/{path}` URL valid ~30–89 min that **works without signing in** (SEC-88-17); files kept |
| I | AI Copilot tools | `ai.query` + each tool's permission | lists of candidates, offers, interviews etc. **Names are shown to the user; the AI provider gets references only, no contact details, no pay.** | names | hierarchy | **yes** — every tool run is written to `ai_action_logs` | 20 messages / min, 8 tool calls / turn | most tools cap at 6–100 rows and silently drop the rest; three tools have no cap | no file export |

Not found:
- any `response()->download` in `app/`;
- any candidate-facing download (the portal has none);
- any export of AI knowledge.

Also noted for the owners, but not a candidate export: staff **profile photos are stored on the public disk** and readable by anyone who knows the URL. This needs triage, and no new finding is opened here.

## 3. Cross-cutting facts

1. **No export or download is audited.** The only traces are the Filament `exports` row (user, exporter, row counts, times) and `ai_action_logs` for AI tools. SEC-88-13.
2. **No rate limits** on any export action or download route.
3. **No row caps** on Filament exports. Each staff role can export candidate contact details for its whole hierarchy; chro can export the whole organisation in one file.
4. **Export files are kept forever,** re-downloadable through permanent links.
5. **One permission, `reports.export`, covers** contact PD, incentive pay and performance data, and all five staff roles hold it.
6. **Compensation gating is inconsistent.** Only `offered_ctc` in the offer export is gated; offer letters, the incentive export and statements are not (SEC-88-15). Because every default role holds `compensation.view`, the gap matters only for custom roles.
7. **Formula injection is not neutralised** anywhere (SEC-88-12). The public career form feeds candidate names.
8. **Positive:** hierarchy scoping is applied consistently across exports, reports, statements and AI tools, and Filament export downloads are owner-only.
9. **Operational note:** exports run on the `default` queue, which Phase 8.7 intended to keep empty (PF-88-03).

## 4. D8.8-EXPORT-001 — decision areas

No answer is assumed. Each needs the named owner's decision, recorded in `phase-8-8-decision-record.md`.

| # | Decision area | Owner | Relevant facts |
|---|---|---|---|
| X-1 | **Allowed export roles:** which roles may export which datasets — candidate contact details (A1), names (A2–A4, A6), pay (A5, A6, D1–D2, E), performance (A7)? Should `reports.export` be split? | Security + Product Owner | §1, §3.5 |
| X-2 | **Export scope:** keep the hierarchy scope; should the organisation-wide export (chro) be restricted or need a second person? | Security + Product Owner | §2.1 |
| X-3 | **Maximum volume:** a row cap per export or per period; bulk thresholds that need more control (D8.8-017)? | Product Owner + Security | no cap exists |
| X-4 | **Sensitive fields:** which fields may leave in an export (contact details, pay, free text), and under which permission (D8.8-030; SEC-88-15)? | Security + Product Owner | §3.6 |
| X-5 | **Audit requirements:** what is recorded for an export and for each download — who, dataset, filters, columns, row count, time, file — and how long audit is kept (with D8.8-RETENTION-001 R-5)? | Security + Legal/Compliance | §3.1; SEC-88-13 |
| X-6 | **Export reason:** must the user state a purpose? | Product Owner + Legal/Compliance | not present |
| X-7 | **Approval requirements:** does any export (e.g. contact details above a threshold, organisation-wide) need prior approval, and by whom? | Product Owner + Security | not present |
| X-8 | **File expiry:** how long generated files and their rows are kept (D8.8-016)? | Legal/Compliance + Security | §3.4 |
| X-9 | **Download controls:** should download links expire? How many downloads are allowed? Should the download route run the panel's staff-access and MFA middleware (SEC-88-24)? Signed file URLs (SEC-88-17)? | Security | §2.2 B, H |
| X-10 | **Bulk export restrictions:** rate limits and per-user / per-day limits; behaviour for departing or suspended users? | Security + Product Owner | no rate limit exists |
| X-11 | **Formula neutralisation:** confirm it is required for every export and report (engineering default E-05)? | Security (sign-off) | SEC-88-12 |
| X-12 | **Exports into the AI knowledge base:** block, or require a data-class declaration (E-10, SEC-88-16)? | Security | `AiDocumentForm` accepts CSV / XLSX |
| X-13 | **Freeze treatment of SEC-88-03:** resolve before the Phase 8.8 freeze, or formally accept / defer it for the freeze (with a target phase), recorded as an approved decision? | Security + Product Owner | SEC-88-03 is High and blocks the freeze until one of these is recorded |

## 5. What would follow a decision (not approved, not designed)

After X-1–X-12, engineering would design:
- audited exports and downloads;
- caps and limits;
- link and file expiry;
- role and field restrictions;
- formula neutralisation;
- the download-route middleware.

This needs a separate implementation brief and approval. Nothing here authorises it.

## 6. Freeze impact

SEC-88-03 is **High** and stays High. Phase 8.8 cannot be frozen until X-13 is answered:
- either the decision is implemented and verified;
- or Security and the Product Owner formally accept or defer SEC-88-03 for this freeze in an approved decision, naming a target phase.

## 7. Implemented (`05a9fd3`, owner decisions of 2026-10-01)

| Control | Where | Evidence |
|---|---|---|
| 10,000-row cap on every table export; over the cap nothing is queued and the refusal is audited (`export_refused`) | `AppServiceProvider::configureExports()`, `App\Services\Export\ExportGovernance` | `SEC8803ExportGovernanceTest` |
| `export_requested` audit on the export: exporter, selected columns, filters, search, row count, requester | `ExportGovernance::rememberRequest()` / `recordRequested()` (`Export::created`) | `SEC8803ExportGovernanceTest` |
| Download only by the owner, only within 24 hours of completion (`ExportPolicy`); suspended / separated users refused (`Gate::before`, `EnforceStaffAccess`); MFA required where enforced (`EnsureStaffMfa`) — added to Filament's `filament.actions` route group | `App\Policies\ExportPolicy`, `AppServiceProvider` | `SEC8803ExportGovernanceTest` |
| `export_downloaded` audit on every served download | `App\Http\Middleware\AuditExportDownload` | `SEC8803ExportGovernanceTest` |
| Formula neutralisation on every export column (Filament `preventFormulaInjection()` as a project default) and every report CSV cell | `AppServiceProvider`, `ReportExportService::neutraliseFormula()` | `SEC8812FormulaNeutralisationTest` |
| `compensation.view` on the offer letter download, the incentive export and other people's statements; own statement exempt | `OffersTable`, `RecruiterIncentiveCalculationsTable`, `ViewRecruiterIncentiveCalculation`, `IncentiveStatementService::canDownloadFor()` | `SEC8815CompensationGatingTest` |
| Audit of offer-letter (`offer_letter_downloaded`), statement (`incentive_statement_downloaded`) and report (`report_exported`) downloads | the same actions; `RecruitmentReports` | `SEC8813DownloadAuditTest` |
| Private files no longer served at `storage/{path}`; previews through `files.private` (5-minute signed link bound to the issuing staff user, staff-access + MFA, `private_file_downloaded` audit) | `config/filesystems.php`, `PrivateFileController`, `routes/web.php` | `SEC8817PrivateFileAccessTest` |

**Not implemented (not decided):** stored export files and rows are still kept indefinitely (X-8, with SEC-88-02); one permission (`reports.export`) still covers all export types (X-1); no export reason, approval or rate limit (X-6, X-7, X-10); AI-knowledge upload guard (X-12, deferred). Report CSVs and statements stay unlimited in size because they are naturally bounded (aggregates, one person × month).
