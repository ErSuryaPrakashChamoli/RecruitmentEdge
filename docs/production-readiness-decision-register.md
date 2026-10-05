# Production Readiness — Decision Register

**For:** the project owner, with Security, Finance, Operations and the release owner.

**Date:** 2026-10-05.

**This register does not choose.** Each decision lists:
- the options that are known;
- what the system does today if nobody decides (the "default today");
- what the decision blocks.

**Recommendations are deliberately absent.** Where an earlier phase register holds a decision, it is referenced by its ID, not repeated.

**Status of every decision below: OPEN.**

## 1. Decisions raised by production readiness

| ID | Decision | Known options | Default today | Needed before | Blocks / evidence |
|---|---|---|---|---|---|
| PRD-01 | **Which code line production runs, and which commit is released** | The documented line `main` with or without the hotfix line (`599f0c5`, which contains `2fab3fd`); the release candidate (SaaS line, contains both fixes by content) | Unknown: production's actual line is not visible to this repository | First release | PR-03 (closed for the candidate); release checklist §1 |
| PRD-02 | **Tenant #1's attributes** (`TENANT_ONE_*`) | slug, name, legal name, time zone, locale, currency, country | slug `main`; name from `APP_COMPANY_NAME`/`APP_NAME`; `Asia/Kolkata`; `en`; `INR`; `IN` | First SaaS release (the migration reads them) | D-S1-O2 |
| PRD-03 | **Tenant #1's owner** | Any active member of tenant #1 holding CHRO | **No owner**: the migration sets none. `ops:verify-integrity` warns until one is assigned | First SaaS release (post-migration step) | Release checklist RELEASE step 10; PR-04 |
| PRD-04 | **Database session time zone** | `DB_TIMEZONE=+00:00`; the server's own zone set to UTC; keep the server's zone and accept the database-side difference | Unset: the server's zone (IST on the development host). Preflight warns | First release; decided **from the production-copy rehearsal** | PR-02; release checklist §5 |
| PRD-05 | **Audit immutability model** | Install the `audit:protect` triggers (needs a privileged database user); accept application-only immutability; external log shipping | Application-only; preflight and integrity warn | First release | S7-12 (Medium); D-S7-O8, D-S5-O10 |
| PRD-06 | **Billing at launch** | A production payment provider (adapter to be built, D-S4-O1); manual or contract billing; no billing for the pilot | Only the fake adapter, refused in production | Any billed customer | D-S4-O1…O11 |
| PRD-07 | **Pilot tenant and its sign-off owner** | An internal tenant (discovery A28); who signs off before expanding | None designated | Pilot | Discovery A28 |
| PRD-08 | **CORS origins for `/api/*`** | Named integrator origins; none (server-to-server only); any origin | Any origin, no credentials; preflight warns | Enabling the API for anyone | PR-01; D-S6-O1 |
| PRD-09 | **Alert recipient and on-call** | An operations mailbox; a monitoring vendor's routing; named people and rota | **None.** A production container now refuses to start without a valid `PLATFORM_NOTIFY_EMAIL` | First release | D-S5-O8, D-S7-O6; stop conditions 11–12 |
| PRD-10 | **Maintenance window and rollback decision window** | Durations measured in the production-copy rehearsal | Not set | First release | Release checklist §5–§6; `failed-deployment.md` §3 |
| PRD-11 | **Pre-opening smoke tests through a maintenance bypass** | Use `php artisan down --secret=…` so testers check before reopening; or smoke-test right after reopening | No bypass (the documented drain does not use one) | Each release (release owner) | Release checklist RELEASE step 11 |
| PRD-12 | **How long a previous `APP_KEY` is kept after a compromise** | Remove at once (outstanding portal and booking links break, up to 14 days); keep until links expire (the leaked key stays valid for links) | Not set | Any key rotation after a leak | `app-key-and-secret-rotation.md` §A step 6 |
| PRD-13 | **Incident notification policy** (tenants, individuals, authorities) | Owner / security / legal to define | **None defined.** Runbooks record the facts needed and leave the decision open | First release | `security-incident.md`, `leaked-api-credential.md`, `compromised-integration.md` |
| PRD-14 | **Webhook failure alerting** | Thresholds for failures per hour, open circuits, retrying deliveries; whether a customer's failing endpoint pages the platform | Counts visible on `/health/queue`, no alert | Enabling webhooks for anyone | PR-05; D-S6-O5, D-S7-O6 |
| PRD-15 | **Bulk credential revocation and replay limits** | Build them; accept the per-credential and per-tenant controls as they are | Per credential, per tenant (entitlement), replay unlimited and audited | Enabling the API or webhooks for anyone | PR-06; D-S6-O3, D-S6-O5 |
| PRD-16 | **Remaining runbooks before release** | Billing webhook failure (A29 #9) once a provider exists (it depends on the provider's events); extend the partial outage runbooks (A29 #1, #3, #5) now or after monitoring exists | Not written (code-closure PRC-02, PRC-04) | The owner's choice of timing | `docs/production-readiness-code-closure.md` |

## 2. Decisions from SaaS-1…7 that block production

Referenced, not repeated. Each phase's register holds the options and defaults.

| Area | Decisions | Before production? |
|---|---|---|
| Release order, tenant #1, old links | D-S1-O1, D-S1-O2 (= PRD-02), D-S1-O3 | Yes |
| Platform operators | D-S1-O5, D-S2-O1, D-S5-O7 | Yes |
| Tenant-wide MFA | D-S2-O4 | Yes |
| Onboarding communication | D-S2-O2 | Yes (release notes) |
| Commercial catalog, tenant #1's plan | D-S3-O1, D-S3-O5 | Before any customer |
| Retention after cancellation | D-S3-O7 | Before any cancellation |
| Billing | D-S4-O1 provider, O2 prices, O7 GST, O8 numbering, O11 finance operators (also O3–O6, O9, O10) | Before billing (PRD-06) |
| Platform policies | D-S5-O1…O13: support duration and approval, ownership transfer, deletion grace, retention after deletion, export retention, notification and on-call, purge authorization, audit immutability, restoration, rehearsal, orphan identities | 12 before production |
| API and integrations | D-S6-O1…O15: exposure domains, authentication, credential lifecycle, rate limits, retries, event catalogue, secret storage, versioning, developer access, documentation, plans, retention, rehearsal, egress | 11 before enabling for anyone |
| Infrastructure choices | D-S7-O1 secrets, O2 egress, O3 domain/TLS, O4 cache, O5 workers, O6 monitoring, O7 backup, O8 audit (= PRD-05), O9 retention, O10 rehearsal, O11 workload limits, O12 deployment and CI, O13 database scaling | 10 before production |

## 3. How a decision is recorded

For each decision, record:
- the choice;
- who decided, and the date;
- what it changes: a configuration value, a runbook step, or code to write.

Add it to the phase register that owns it, or to §1 above. A decision that needs code (for example a billing adapter, or pruning with a retention value) becomes engineering work with its own tests. **The release checklist's gates tick only after the decision is recorded.**
