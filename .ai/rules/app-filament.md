---
paths:
  - 'app/Filament/**'
---

# App Filament

## Pick applications with ApplicationPicker, never a code-only Select
Any form field choosing a CandidateApplication must use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker::make() (chain ->required() etc.). Application codes alone aren't recognisable: its options read "name · mobile · APP code · REQ code · stage", search matches candidate name/mobile/code, and options + a validation rule are hierarchy-scoped via CandidateApplicationResource::getEloquentQuery(). Pass modifyOptionsQueryUsing to narrow only the listed options (e.g. InterviewForm lists Active applications) — the rule stays scope-only so editing older records still saves. Server-side lookups in actions should use ApplicationPicker::selectableApplications()->findOrFail().

## Filament closures are injected by parameter NAME — use $query, $data, $record
Filament resolves closure arguments by name. In Tab::modifyQueryUsing(), Filter::query(), TernaryFilter::queries() etc. the builder parameter must be named `$query` (and filter state `$data`). `fn (Builder $q) => ...` silently receives a fresh container-built Builder with no model and fails later with "newQueryWithoutRelationships() on null" inside the table view. Also: Table::defaultSort() does not take a query closure — use a column's ->sortable(query: fn (Builder $query, string $direction) => ...) and defaultSort('that_column').

## Never name a ->state() closure parameter $state
In Filament v5 a `->state(fn ($state) => ...)` (or `fn (array $state)`) closure recurses forever: resolving `$state` calls getState(), which re-evaluates the same closure. The request hangs until PHP dies (seen as a page that never loads, a 75k-frame trace in the server log). In ->state() use `$record` or other utilities; to reformat an existing value use ->formatStateUsing(fn ($state) => ...).

## Filament native tenancy: uploads, uniqueness, Users/Roles scoping
SaaS-1: the panel is ->tenant(Tenant::class, slug) under /admin/{slug}; SetTenantContextFromPanel (persistent) makes the selection the TenantContext, but models/policies enforce the tenant on their own. FileUpload ->directory() must be fn () => TenantStorage::path('area') (arch test). Uniqueness of tenant fields uses ->scopedUnique() (Laravel unique/exists rules bypass scopes); users.email stays ->unique() (global identity). UserResource and RoleResource set $isScopedToTenant = false and scope explicitly (memberships / Role::forCurrentTenant()) — Filament's scope on Role would corrupt spatie's shared permission cache.
