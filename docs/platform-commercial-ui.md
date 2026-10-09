# Platform Commercial & Tenant Management UI

**For:** platform operators, the engineers who maintain the platform panel, and the release owner.

**Branch:** `feature/platform-commercial-ui`, based on `3e2f2fb`.

**This workstream does not implement A1/A2/A3/A4/A5/A6.** They are documented below as extension points (§14–§20).

---

## 1. Purpose

The platform panel (`/platform`) is the control plane of Recruitment Edge. Before this workstream it listed tenants and handled support, compliance, deletion and operational events. Everything commercial was done with artisan commands, or was not visible at all:
- creating a tenant;
- changing its plan;
- entitlement overrides;
- subscriptions, offline payments, refunds and voids;
- the plan catalog, prices, invoices and payments (not visible anywhere).

This workstream adds a commercial control-plane UI on top of the existing SaaS-1 to SaaS-7 services. **It adds no business logic, no migration and no table.**

## 2. Architecture

```
Filament page / widget              presentation; visibility only mirrors authorisation
        ↓
PlatformAuthorization               capability of the signed-in operator (SaaS-5)
        ↓
TenantCommercialService             NEW thin adapter: authorise → attribute → call
        ↓
Existing authoritative services     SaaS-3: TenantProvisioningService, PlanAssignmentService, EntitlementOverrideService
                                    SaaS-4: SubscriptionService, PaymentService, InvoiceService, PriceCatalogService
        ↓
Existing domain state               locks, state machines, idempotency, audit — unchanged
```

**Reads** come from `PlatformDirectory`. It is the one class reviewed for cross-tenant reads, and it is already on the tenancy allow-list.

**Why the adapter is needed:** an architecture test (`CommercialArchitectureTest`) forbids Filament code from reaching the commercial services directly. The adapter is the same pattern as the existing `TenantAdministrationService`.

**What the adapter does:**
- checks the operator's platform capability;
- attributes the audit to the operator (`AuditLog::asPlatformOperator`);
- turns UI input into the services' arguments.

**What it never does:**
- decide a business rule;
- write a model directly;
- record an audit entry of its own.

## 3. Platform navigation

| Group | Page | URL | Capability |
|---|---|---|---|
| — | Platform overview | `/platform` | any platform operator |
| Tenants | Tenants | `/platform/tenants` | `platform.tenants.view` |
| Tenants | Create tenant | `/platform/create-tenant` | `platform.tenants.manage` |
| Tenants | Deletions | `/platform/deletion-requests` | `platform.deletion.manage` (existing) |
| — | Tenant detail | `/platform/tenants/{id}` | `platform.tenants.view` (tabs and actions by capability, §6) |
| Commercial | Plan catalog | `/platform/plans` | `platform.commercial.manage` |
| Commercial | Subscriptions | `/platform/subscriptions` | `platform.commercial.manage` |
| Commercial | Invoices | `/platform/invoices` (`?tenant={id}`) | `platform.commercial.manage` |
| Commercial | Payments | `/platform/payments` (`?tenant={id}`) | `platform.commercial.manage` |
| Support | Support access | `/platform/support-grants` | existing |
| Compliance | Platform audit, Compliance exports | `/platform/platform-audit` (`?tenant={id}`), `/platform/compliance-exports` | existing |
| Operations | Events | `/platform/operational-events` | existing |

**The separate security planes are unchanged:**

| Plane | URL |
|---|---|
| Platform operators | `/platform/login` |
| Tenant staff | `/admin/login` → `/admin/{slug}` |
| Candidates | `/portal/{slug}` |
| Public careers site | `/careers/{slug}` |

- A platform operator is never a tenant member.
- A tenant administrator never reaches `/platform`: they are signed out of the platform panel and redirected to its login.
- There is no impersonation.

## 4. Pages

| Page | What it shows | Source |
|---|---|---|
| **Platform overview** | Existing operational stats, plus **Commercial** stats (below), plus the latest platform events and the latest platform activity | `PlatformDirectory::commercialOverview()`, `audit()`, `PlatformEvent` |
| **Tenants** | Name/slug, lifecycle, plan + version, subscription state, billing state, owner, active members, trial end, deletion, support grants, created/updated.<br>Search by name or slug.<br>Filters: status, plan, subscription state, billing state, trial ending in 7 days, created date. Server-side pagination. | `PlatformDirectory::tenants()`: one query with subselects |
| **Create tenant** | Wizard: Company → Owner → Plan → Trial → Review (§5) | `TenantCommercialService::provision()` |
| **Tenant detail** | Nine tabs (§6) | `PlatformDirectory`; each tab's data is read once per request |
| **Plan catalog** | Every plan version: plan, code, offered status, version, published date, tenants on it, number of current prices.<br>**View** shows the version's grants by entitlement, and its current and retired prices. **Read-only.** | `PlatformDirectory::planCatalog()`, `grants()`, `prices()` |
| **Subscriptions** | Tenant, plan + version, status, price snapshot and interval, billing source, contract reference, active since, current period, trial/cancel/past-due/grace dates.<br>Filters: live, status, source, interval, plan, start date. **Read-only**: changes are made on the tenant detail. | `PlatformDirectory::subscriptions()` |
| **Invoices** | Number, tenant, status, subtotal, tax (as recorded), total, paid, balance, issued/due/paid dates.<br>View shows the stored snapshot.<br>Filters: status, due date passed, currency, issue date, tenant (`?tenant=`).<br>Actions: **Record payment**, **Void** (§7). | `PlatformDirectory::invoices()` |
| **Payments** | Tenant, invoice, amount, refunded, status, method/provider, reference, date, failure.<br>Filters: status, method, currency, date, tenant (`?tenant=`).<br>Action: **Refund** (§7). | `PlatformDirectory::payments()` |

**What the Commercial overview counts:**
- tenants, by status;
- plans in use (tenants per plan);
- subscriptions needing attention: past due, unpaid, ending at period end;
- open invoices, with the outstanding amount per currency;
- failed payments in the last 30 days;
- lifecycle problems: suspended tenants and failed provisioning.

It shows no forecasts and no converted totals.

## 5. Create tenant workflow

| Step | Fields | Notes |
|---|---|---|
| Company | Company name\*, legal name, tenant slug\*, country, timezone, currency, locale | Region fields are optional. **Left empty, the provisioning request's own defaults apply** (§16); the UI never states or copies them. Input shape is checked: 2-letter country, 3-letter currency, IANA timezone, locale format |
| Owner | Owner name\*, owner email\* | The owner is invited as owner and CHRO through the existing invitation flow. An existing login is invited, never duplicated |
| Plan | One of the plans offered to new tenants, at its latest published version, with its grants | Internal plans (legacy) are not offered |
| Trial | Start with a trial; trial days | Bounds are decided by the provisioning request (1–90) |
| Review | Everything above | Nothing is created before **Create tenant** |

**What the provisioning service decides** (the existing `TenantProvisioningService`, the same one `tenants:provision` uses):
- slug format and reserved slugs;
- the plan;
- the owner invitation;
- trial or active;
- idempotency.

**Outcomes:**

| Outcome | What the operator sees |
|---|---|
| Success | Redirect to the tenant detail |
| Refusal (taken slug with other details, reserved slug, plan not offered) | **"Not done"** with the service's reason; the form keeps its input |
| Unexpected failure | "Provisioning did not finish… can be retried", with no exception detail. The service records the error on the tenant and raises a critical platform event |
| Slug of an already provisioned tenant | **"Nothing new was created"**, and the tenant is unchanged (§21, finding F1) |

## 6. Tenant detail tabs and actions

| Tab | Content | Shown to |
|---|---|---|
| Overview | Slug, status and reason, legal name, plan + version, subscription, owner, members/invitations, trial and access end, failed jobs, deletion, created/updated, latest platform action | `tenants.view` |
| Commercial | The current plan assignment (plan, version, since, source, by, reason). For each registry entitlement: the plan value, the current override (value, reason, end) and the **effective value as decided by `EntitlementService`** | `tenants.view` |
| Subscription & billing | The live subscription (stored snapshot), the latest five invoices and payments, and links to the filtered Invoices and Payments pages | `commercial.manage` |
| Members | Paginated members: name, email, membership state, owner, roles, joined. Pending invitations: email, name, invited, expiry. **No credentials, MFA data or sessions** | `tenants.manage` |
| Configuration | The profile (country, timezone, currency, locale, MFA policy); **counts only** of organisation, recruitment, templates/automation and integration records; feature states | `tenants.view` |
| Usage | Each plan limit: used, allowed, utilisation, over/within (existing `EntitlementService` usage) | `tenants.view` |
| Lifecycle | Status, reason, since, provisioned, provisioning error, trial, access end, deletion, subscription | `tenants.view` |
| Audit | The latest platform actions and platform events for the tenant, and a link to the full audit | `audit.view` |
| Health | Lifecycle, provisioning, subscription, open invoices, plan limits, failed jobs, unacknowledged events, support access, deletion — each rated OK / needs attention / problem | `tenants.view` |

**Header actions:**

| Group | Actions | Capability |
|---|---|---|
| Lifecycle (existing) | Suspend, Activate, Cancel, Extend trial | `tenants.manage` |
| **Commercial** (new) | Change plan (with a before → after comparison), Set entitlement override, Remove entitlement override | `tenants.manage` |
| **Billing** (new) | Subscribe (manual or contract), Change subscription plan, Cancel at period end, Cancel now | `commercial.manage` |
| Existing | Transfer ownership, Request support access, Compliance export, Request deletion, Audit | as before |

The existing lifecycle confirmations now state what happens, when, and whether it can be undone. **Cancel is stated as irreversible:** a cancelled tenant can only move on to deletion.

## 7. Actions: UI → existing service → CLI equivalent

| UI action | Existing service | CLI | Repeat / double submit |
|---|---|---|---|
| Create tenant | `TenantProvisioningService::provision` | `tenants:provision` | The same request returns the same tenant. Other details on a taken slug are refused |
| Change plan | `PlanAssignmentService::latestVersion` + `assign` | `tenants:plan` | The same version, under the tenant lock, returns the current assignment and writes nothing |
| Set / remove override | `EntitlementOverrideService::set` / `remove` | `tenants:entitlement` | One current override per key (tenant lock + unique). An identical repeat adds one history row with the same value (finding F3) |
| Subscribe (manual / contract) | `PriceCatalogService` price + `SubscriptionService::subscribe` | `billing:subscription subscribe` | One live subscription per tenant (`unique(tenant_id, is_live)` + lock). The same request returns it |
| Change subscription plan | `SubscriptionService::changePlan` | `billing:subscription change-plan` | The same price returns unchanged |
| Cancel at period end / now | `SubscriptionService::cancelForPlatform` | `billing:subscription cancel / cancel-now` | A repeat is refused (Cancelling, or terminal) |
| Record payment (whole balance) | `PaymentService::recordManualPayment` | `billing:payment` | The amount is the stored balance, never typed. The invoice becomes Paid under its row lock, so a repeat is refused |
| Refund (whole remainder) | `PaymentService::refund` | `billing:payment` | The payment becomes Refunded, so a repeat is refused |
| Void | `InvoiceService::void` | `billing:payment` | Only an Open invoice with no payment; a repeat is refused |

Each of these is covered by a test that submits it twice (`tests/Feature/Platform/PlatformCommercialActionsTest.php`).

**Confirmations.** Destructive actions state what changes, which tenant, the amount, the current state, the resulting state, and whether it can be undone.
- **Cancel now** requires typing the tenant's slug.
- **Void** and **Refund** require typing the invoice number.

**Not exposed** (the backend does not authorise it for a platform operator, or billing owns it):

| Not exposed | Why |
|---|---|
| Subscription Resume, and tenant-side cancel | These are `billing.manage` actions of the tenant |
| Mark past due | Driven by billing |
| Partial manual payments and partial refunds | §21, gap G1 |
| Starting provider-billed subscriptions | Provider billing starts with the payment provider |

## 8. Authorization

| Capability | Roles (V1) | Covers |
|---|---|---|
| `platform.tenants.view` | Administrator, Support, Compliance | Tenants list, tenant detail (overview, commercial, configuration, usage, lifecycle, health) |
| `platform.tenants.manage` | Administrator | Create tenant, lifecycle, ownership, plan change, overrides, Members tab |
| `platform.commercial.manage` (**new**) | Administrator | Plan catalog, Subscriptions, Invoices, Payments, Subscription & billing tab, billing actions, Commercial overview |
| `platform.audit.view` | Administrator, Compliance | Audit tab, recent platform activity |
| `platform.operations.view` | Administrator, Support | Recent platform events |

**Every mutation is authorised three times:**
1. the page mirrors the capability, as presentation only;
2. the adapter calls `PlatformAuthorization::authorize`;
3. the domain service calls `PlatformOperatorGate` (platform Administrator).

**Server-side safeguards:**
- Billing records are resolved inside the tenant on screen, so another tenant's invoice or payment id resolves to nothing.
- Record ids in Livewire state are `#[Locked]`.
- The Members widget re-checks its capability on every request.
- No authorisation depends on an email address or a hard-coded user.

**This is the current V1 authorisation model, not the final commercial RBAC.** A commercial SaaS may later distinguish:
- Platform Super Administrator;
- Platform Administrator;
- Commercial/Billing Operator;
- Support Operator;
- Compliance Operator;
- Read-only Platform Analyst.

These roles are not built (A3).

## 9. Services and adapters

| Class | Role |
|---|---|
| `App\Services\Platform\TenantCommercialService` (**new**) | Thin adapter, listed explicitly in `CommercialArchitectureTest`'s platform-services allow-list |
| `App\Services\Platform\PlatformDirectory` (**extended**) | Reads: tenant list subselects, billing state, plan/subscription/billing filters, commercial overview, plan catalog, grants, prices, offered plans, price options, current assignment, entitlements, subscriptions, invoices, payments, members, member roles, pending invitations, configuration counts, health, recent audit/events |
| `InteractsWithPlatform` (**extended**) | `permits()`: a capability asked once per request per page, instead of once per row. `perform()`: may build its own success notification |
| Unchanged | `TenantProvisioningService`, `PlanAssignmentService`, `EntitlementOverrideService`, `EntitlementService`, `SubscriptionService`, `PaymentService`, `InvoiceService`, `PriceCatalogService`, `TenantAdministrationService`, `TenantOwnershipService`, `TenantDeletionService`, `SupportAccessService`, `ComplianceExportService` |

## 10. Data sources and commercial model

- **Plans and versions:** `plans`, `plan_versions`, `plan_entitlements`. Published versions are immutable.
- **Prices:** `billing_prices`. They are never edited; a new amount retires the current price.
- **Subscriptions, invoices and payments:** `billing_subscriptions`, `billing_invoices`, `billing_payments`, as stored by SaaS-4.
- **Money:**
  - always `App\Services\Billing\Money`: integer minor units, the record's own currency, formatted with the currency code and the currency's own precision;
  - no float;
  - no conversion;
  - no recomputation of an issued invoice from current prices.
- **Tenant metadata:** `tenants`, `tenant_memberships`, `tenant_invitations`, `tenant_plan_assignments`, `tenant_entitlement_overrides`.
- **Configuration counts:** `COUNT(*)` by `tenant_id` on the tenant's own tables. Records are never shown.

## 11. Audit behaviour

- **No new audit table, entry or event type.** The services already audit; the adapter attributes the entry to the operator: `actor_kind = platform`, `user_id` = the operator.
- **`PlatformDirectory::PLATFORM_ACTIONS` now includes the commercial and billing actions.** Before, they were recorded but did not appear in the platform audit. The added actions are:
  - provisioning;
  - `plan_changed`;
  - override created/changed;
  - subscription created / plan changed / each status;
  - invoice issued/paid/voided;
  - payments recorded/succeeded/failed/refunded;
  - billing anomalies.
- **Kept out:** tenant-side billing details and high-volume provider traffic (webhooks, payment attempts).
- **The audit stays append-only.** Nothing in this UI edits or deletes an audit entry (`PlatformArchitectureTest`).

## 12. Performance

Measured in the test suite (SQLite, sequential Livewire renders). Each page costs **the same number of queries with three times the tenants and billing records** (`PlatformCommercialViewsTest`).

| Page | Queries per render |
|---|---|
| Tenants | 5 |
| Subscriptions | 7 |
| Invoices | 6 |
| Payments | 2 |
| Plan catalog | 5 |
| Tenant detail (all tabs) | 53 |

**How the counts stay flat:**
- Lists use one query with subselects and eager loading, plus server-side filters and pagination.
- Capabilities are asked once per request (`permits()`); before that, each table row asked again.
- Tenant-detail tab data is memoised per request; Filament evaluates each entry's state several times per render. This brought the tenant detail from 215 queries to 53.
- The Members table is lazy-loaded.

**No index or migration was added.** Cross-tenant status filters on the billing tables scan by status. That is acceptable at current volumes; it should be revisited (an index proposal, not a silent migration) if those tables grow large.

## 13. Security model

- Server-side authorisation for every action (§8).
- Tenant scoping is unchanged. Cross-tenant reads happen only in `PlatformDirectory`, and show metadata and counts only. Billing writes are bound to the tenant on screen.
- **Never trusted from the browser:**
  - a tenant id;
  - an amount for a payment or refund (taken from the stored record);
  - a role.
- **Never shown:**
  - passwords, MFA secrets, session data;
  - API secrets, OAuth tokens;
  - provider secrets, `APP_KEY`.
- CSRF and validation come from Laravel and Filament. Destructive actions need typed confirmation.

## 14. Future extension points

This UI is structured so that the A1–A6 capabilities add actions to the existing screens, without replacing them.

| Gap | Extension point |
|---|---|
| A1 Plan authoring | Actions on the Plan catalog table and its View |
| A2 Platform settings | A new Settings group in the navigation |
| A3 Platform team | A new page in the Support or Settings group |
| A4 Tenant profile editing | An Edit action on the tenant detail's Configuration tab |
| A6 Candidate email opt-out | A field in the Create tenant wizard |

Each needs its own approved backend workstream first.

## 15. A1 — Plan and plan version authoring

| | |
|---|---|
| Current limitation | Plans and versions come from code (`PlanCatalog`) through `plans:sync`. `sync()` also resets the name, description and status of code-defined plans on every run. Prices are published with `billing:price`. The UI is read-only |
| Backend involved | `PlanCatalogService` (`sync`, private `assertComplete`/`assertUnchanged`), `PlanCatalog`, `PriceCatalogService::publish`, models `Plan`/`PlanVersion`/`PlanEntitlement` (immutable once published) |
| Smallest backend change | A public `PlanCatalogService` method that publishes the next version from data, reusing `assertComplete`; create a plan; retire a version or plan. `sync()` must stop overwriting plans authored in the UI. Price publishing needs only a thin adapter method, because `PriceCatalogService::publish` already exists |
| Migration | No (tables exist) |
| Security | A new capability (Commercial/Billing Operator or Administrator), two-person review for publishing if wanted, audit `plan_version_published` |
| Tenancy | None (platform tables) |
| Billing | Prices attach to a version; a new version needs its own prices. Subscriptions keep their snapshot |
| Existing tenants | None until moved: a tenant stays on its version (D-S3-04). An optional "move tenants on vN to vN+1" must be explicit |
| Client #1 | None (it stays on `legacy`) |
| Approach | Versioned publishing (never edit in place); Plan catalog page actions "Publish new version" / "Retire"; tests mirroring `PlanCatalogTest` |
| Classification | **Backend service required** (no database change) |

## 16. A2 — Platform settings

| | |
|---|---|
| Current limitation | Platform-wide values live in config/`.env`: `billing.grace_days`, `collection_retry_hours`, `invoice_number.prefix`, `invoice_number.year_starts_month`, `platform.brand.name`, `platform.notify_email`, support and deletion windows, compliance-export retention, `identity.invitations.ttl_hours`. **Provisioning defaults** (country, timezone, currency, locale) exist only as `ProvisioningRequest` constructor defaults, copied again in the `tenants:provision` signature. No explicit defaults provider exists |
| Backend involved | `config/billing.php`, `config/platform.php`, `config/identity.php`, `ProvisioningRequest`, their read sites |
| Smallest backend change | A typed platform-settings service (definitions with config fallback, cached, audited) and its read sites. Provisioning defaults read from it |
| Migration | **Yes**: one platform table (key/value + audit), or an equivalent store |
| Security | Admin-only; some keys stay env-only (secrets, `mfa.enforce`, the two-person deletion rule, the payment provider) |
| Tenancy | None (platform scope) |
| Billing | Grace days, retry and invoice numbering affect billing behaviour; changes must not rewrite issued invoices |
| Existing tenants | Changes apply going forward |
| Client #1 | A migration must be rehearsed on the Client #1 line, which has already failed one migration |
| Approach | Separate workstream with a migration review; settings page in a new Settings group |
| Classification | **Database change required** |

## 17. A3 — Platform team management

| | |
|---|---|
| Current limitation | Operators are granted and revoked with `platform:operator`; identities are disabled with `identity:disable`. `PlatformAccess::grant/revoke` and `PlatformIdentityService::disable/enable` take no acting operator, so the audit has no actor. No capability covers team management |
| Backend involved | `PlatformAccess`, `PlatformIdentityService`, `PlatformCapability`, `PlatformRole`, `platform_operators` |
| Smallest backend change | An operator parameter on grant/revoke/disable (attributed audit), a `platform.team.manage` capability, a page listing operators and roles |
| Migration | No for the current three roles. Yes if the future roles (§8) need new role storage or permissions |
| Security | High: privilege management. Require MFA (already enforced), a two-person rule for granting Administrator, no self-grant |
| Tenancy | None; an operator never becomes a tenant member |
| Billing | None |
| Existing tenants | None |
| Client #1 | None |
| Approach | Separate RBAC design (the future roles in §8), then UI |
| Classification | **Backend service required**; **architecture change** for the finer roles |

## 18. A4 — Tenant profile editing

| | |
|---|---|
| Current limitation | Name, legal name, timezone, locale, currency, country and branding (`branding.display_name`) are set only at provisioning. No service writes them later; the Configuration tab shows them read-only |
| Backend involved | `tenants` columns, `TenantProvisioningService` (the only writer), `Branding` (reads `display_name`) |
| Smallest backend change | A `TenantProfileService::update(Tenant, array, reason, operator)`: tenant row lock, `CommercialChange`-style audit, validation of the formats |
| Migration | No |
| Security | Admin-only; the slug never changes (it is in URLs) |
| Tenancy | None; profile values never influence isolation |
| Billing | Currency and country here are the tenant's profile, **not** the billing currency of existing subscriptions or invoices (those keep their own) |
| Existing tenants | Only on explicit edit. Today these values are mostly display-only (A5) |
| Client #1 | Its `TENANT_ONE_*` backfill values could be corrected through this, once built |
| Approach | Service + Edit action on the Configuration tab |
| Classification | **Backend service required** |

## 19. A5 — Organisation hierarchy and hard-coded organisational assumptions

| | |
|---|---|
| Current limitation | See `docs/organization-hierarchy-configurability-audit.md`. In short:<br>• role keys versus editable names; seeded roles; the CHRO and `employee` base-role anchors;<br>• MFA required-role list;<br>• fixed requisition manager slots;<br>• automation role targets matched by name;<br>• escalation depth;<br>• tenant currency, timezone and country not used by most tenant-app logic (§22) |
| Backend involved | `RolePermissionSeeder`, `RecipientResolver`, `AutomationRuleService`, `config/identity.php`, `RecruitmentRequisitionForm`, tenant UI money formatting, metrics timezone |
| Smallest backend change | Per item: role keys for UI-created roles, role targets by key, a tenant locale helper read from the tenant row |
| Migration | Some (for example role keys) |
| Security | Role and MFA changes are sensitive; each needs its own review |
| Tenancy | Must stay within the tenant (`Role::forCurrentTenant`) |
| Billing | None |
| Existing tenants | Data migration for role keys / settings |
| Client #1 | Directly affected (its roles predate keys; see the Client #1 repair) |
| Approach | Separate architecture-hardening workstream. **The commercial UI adds no role assumption**: it asks `PlatformCapability`, never a role name |
| Classification | **Architecture change required** |

## 20. A6 — Candidate email opt-out at provisioning

| | |
|---|---|
| Current limitation | `TenantDefaults::apply()` runs `RecruitmentReferenceDataSeeder` with its default `activateEmailTemplates = true`, so a new tenant's starter email templates start **Active** and can email candidates automatically. `ProductionBaselineSeeder` deliberately passes `false`. Provisioning has no option for this, and the Create tenant wizard does not offer one |
| Backend involved | `TenantDefaults`, `RecruitmentReferenceDataSeeder`, `ProvisioningRequest`, `CommunicationTemplateService` |
| Smallest backend change | A `ProvisioningRequest` option (`activateCandidateEmails`), passed to the seeder, included in the request fingerprint; a wizard toggle |
| Migration | No |
| Security | Low; reduces unintended candidate contact |
| Tenancy | Per tenant |
| Billing | None |
| Existing tenants | None (seed-time only); existing templates keep their status |
| Client #1 | None |
| Approach | Provisioning option + audit of the choice in `tenant_provisioned`; the default decided by the owner |
| Classification | **Backend service required** |

**Current default candidate-email behaviour is unchanged by this workstream.**

## 21. Findings, limitations and remaining CLI-only operations

**Findings in existing behaviour** (reported, not changed):

- **F1 — a slug without a recorded request is handed back.** `TenantProvisioningService` returns an existing tenant whose provisioning request was never recorded (Tenant #1 `main`, factory-made tenants), unchanged, for any request on its slug. The CLI reports it as if provisioned. The UI says "Nothing new was created". No data changes.
- **F2 — removing an override does not require a reason.** `EntitlementOverrideService::remove` does not check for an empty reason itself. The UI and the adapter do.
- **F3 — an identical override repeat adds history.** Re-submitting the same override adds a history row with the same value. The effective state is unchanged.
- **F4 — commercial actions were missing from the platform audit view** (fixed in the read filter, §11).
- **G1 — partial payments and refunds are not idempotent.** `recordManualPayment` / `refund` have no idempotency key or external-reference de-duplication, so a repeated partial submission would record twice. The UI therefore offers only whole-balance payments and whole-remainder refunds. Partial amounts stay on `billing:payment`.

**Remaining CLI-only operations:**

| Command | Status |
|---|---|
| `plans:sync` | A1 |
| `billing:price` | Pricing is read-only in this UI. A thin adapter is possible later, no migration needed |
| `billing:payment` (partial amounts) | G1 |
| `billing:subscription subscribe --source=provider` | Provider billing |
| `billing:reconcile` | Scheduled; read-only report |
| `tenants:lifecycle past-due` | Billing-driven |
| `tenants:usage` | Cross-tenant report; per-tenant usage is in the UI |
| `platform:operator`, `identity:disable` | A3 |

**Limitations:**
- No browser test suite exists, so none was run.
- Interactive smoke testing needs an operator's MFA sign-in.

## 22. International SaaS readiness

1. Recruitment Edge is designed for future international SaaS expansion.
2. The current release remains India-capable and current-configuration capable.
3. International billing is **not** implemented in this workstream.
4. Multi-currency is **not** implemented. `billing.currencies` lists INR and USD with their precision. Adding a currency is configuration, not code, but nothing converts between currencies.
5. International tax is **not** implemented. Invoices carry `tax_minor`/`tax_details` as recorded; nothing calculates tax, and nothing assumes GST or a single global rate.
6. Localization is **not** implemented.
7. Regional payment providers are **not** implemented. The billing layer stays provider-neutral; a provider is an adapter around the subscription domain, never the source of truth for entitlements.
8. Data residency is **not** implemented.
9. **This UI introduces no India-only assumption:**
   - amounts use `Money` with each record's stored currency and precision, and no symbol;
   - dates use Laravel/Filament formatting, not a fixed timezone;
   - the Create tenant wizard accepts any ISO country/currency and IANA timezone, and never states a default;
   - contract currencies come from configuration;
   - no GST, India, ₹ or Indian phone format appears in the new code.
10. Future international billing should be a separate, controlled workstream.

**Future billing concepts to keep distinct** (not created now):
- price currency;
- customer billing/presentment currency;
- invoice currency;
- payment currency;
- settlement currency;
- reporting currency;
- FX rate and timestamp;
- tax jurisdiction.

International prices should be **authored per currency**, never derived at runtime from a converted base price.

**Future tax considerations:**
- tax jurisdiction and registration number;
- tax-inclusive/exclusive presentation;
- VAT, GST, sales tax;
- reverse charge, exemptions;
- regional invoice requirements.

**Future residency and compliance considerations:**
- EU, UK, US, Middle East and APAC customers;
- GDPR and data privacy;
- retention and erasure (SEC-88-02 is open);
- regional hosting;
- cross-border transfer;
- AI and data processing (`ai.privacy.egress_mode`).

**Isolation is never affected:** country, currency, timezone and locale never influence tenant isolation. Every tenant, whatever its region, is an ordinary SaaS-1 tenant.

**Existing India-specific assumptions** (found during this work; not changed):

| Assumption | Class |
|---|---|
| `Money::format` (currency code, 3-digit grouping, per-currency precision); `billing.currencies` as configuration | **A** — existing and safe |
| `ProvisioningRequest` defaults `Asia/Kolkata` / `en` / `INR` / `IN` (and the `tenants:provision` signature) | **B** — internationalisation gap (A2 defaults) |
| `billing.invoice_number.year_starts_month = 4` (Indian financial year), one global invoice series | **B** — per-jurisdiction series later |
| Seeded candidate sources include India-specific job boards | **B** |
| ₹ / `money('INR')` hard-coded in about 24 tenant-app files; `now('Asia/Kolkata')` on the tenant dashboard; `metrics.business_timezone` default `Asia/Kolkata`; `communications.default_country_code = 91` | **C** — potential international blocker (tenant currency and timezone not applied) |
| Indian mobile-number patterns in `SensitiveDataRedactor` / `PiiPatternScrubber` | **C** — under-redacts other countries' numbers |
| Tenant locale/currency/timezone not driving tenant-app formatting; per-region invoicing and tax | **D** — separate architecture work (A5 and international billing) |

**No international production-readiness is claimed.**

## 23. Migration and Client #1 implications

- **No migration and no table** were created. The UI uses the existing SaaS schema.
- **No Client #1 data or schema was touched.** The repair commit `e3c5ad2` (on `production`) was not merged into this branch.
- **No production system was accessed.**
- A2 (and parts of A3 and A5) would need migrations. Each must be rehearsed on the Client #1 line, given its migration failure at `2026_09_25_135221_grant_phase_four_permissions`.
