---
paths:
  - 'tests/**'
---

# Tests

## Pest helper functions are global — give them file-specific names
A top-level `function foo()` in a Pest test file, even one inside describe(), is declared globally. A name used in two files crashes the whole suite ("Cannot redeclare function") only when both files load together, which happens in the full or parallel run but not when running a single file. Prefix helpers with the feature (e.g. pipelineApplicationOn(), referralUser()) and check with `grep -rhoE "^\s*function [a-zA-Z_]+" tests | sort | uniq -d`.

## Pest toContain is variadic — never pass a failure message to it
expect($x)->toContain($needle, 'message') treats 'message' as a second needle, and ->not->toContain($needle, 'message') then passes whenever the message text is absent — a silently vacuous assertion (this hid real leaks during Phase 8.1 until a mutation check exposed it). For a custom message use expect(str_contains($x, $needle))->toBeFalse('message') / ->toBeTrue('message'). Mutation-check new security assertions: reintroduce the bug and confirm the test fails.

## Identity-aware test setup (Phase 8.4)
phpunit sets IDENTITY_MFA_ENFORCE=false; MFA tests set config(['identity.mfa.enforce' => true]) (the middleware reads config per request). StaffAccessService is a singleton whose decisions are memoised per user object by identity-change generation and date — if a test changes identity facts with raw writes/factories and then reuses the same User instance, use ->fresh(). EmployeeConversionService::convert needs a manager (explicit or the requisition's); the approver of an AI action must be its requester. Livewire tests: AiCopilot::$conversationId is #[Locked] — use switchConversation(). Filament per-IP login throttle (5/min) bites browser smokes that sign in many users from 127.0.0.1 — clear the cache between logins.

## Reset Livewire state before comparing whole HTML responses
Livewire's "a component rendered this request" flag (SupportAutoInjectedAssets) is static and only reset by Livewire::flushState()/Livewire::test(), so a previous test in the same process that rendered a Filament page over HTTP makes the next test's first HTML response carry injected Livewire assets (later responses in that test do not, because FrontendAssets marks them rendered). Any test that compares full response bodies must call Livewire::flushState() in beforeEach (see SEC8809CareerSiteDoesNotRevealApplicationExistenceTest); the failure only shows under some parallel distributions.

## assertNotified() consumes the session notifications
Filament's assertNotified()/Notification::assertNotified() mounts the Notifications Livewire component, which pulls 'filament.notifications' out of the session. Reading session('filament.notifications') afterwards sees nothing, so a "the link is never shown" assertion becomes vacuous (it survived a mutation in SEC8804PortalLinkNeverShownToStaffTest). To inspect what staff were shown, mount Filament\Notifications\Livewire\Notifications yourself and read ->notifications (title/getBody()) before any assertNotified call.

## Feature tests run on faked local and public disks
Phase 8.9 (P89-DQ-016): tests/Pest.php fakes the local and public disks before every Feature test, then re-registers PrivateFileController::registerTemporaryUrls(). No test writes offer letters, templates or uploads into storage/app. A test that calls Storage::fake('local') again must also call registerTemporaryUrls() again if it needs signed preview URLs. To check for leaks: run a suite, then `find storage/app -newer <marker>` should be empty.

## Tests run inside the "acme" tenant; cross-tenant fixtures via TenantWorld
SaaS-1: TestCase (RefreshDatabase tests) creates tenant slug "acme" and calls actInTenant() (TenantContext + Filament tenant + {tenant} URL default). Panel paths are /admin/acme/…, careers /careers/acme/…, portal /portal/acme/…. Raw DB::table()->insert() on tenant tables must include 'tenant_id' => $this->tenant->id. A hand-built queue payload needs 'tenant_id' and 'illuminate:log:context' => Context::dehydrate(), or the worker hydration clears the tenant. Two-tenant fixtures: Tests\Feature\Tenancy\TenantWorld::build(). Concurrency tests run in tenant "concurrency" (Race::prepareDatabase).

## SaaS-2 test fixtures: IdentityWorld; Livewire replays flush state
Multi-tenant identity fixtures: Tests\Feature\IdentityAccess\IdentityWorld::build($this->tenant) (acme + beta, persons A–E of the authorisation matrix, adminA/adminB). User::factory()->create(['employee_id' => …]) still works: the factory moves the link onto the membership of the current tenant. When replaying a raw Livewire update more than once in one test, call Livewire::flushState() before each (Livewire skips persistent middleware already applied in the "same request"). Concurrency emails must be lowercase (invitations are normalised).
