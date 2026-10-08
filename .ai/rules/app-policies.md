---
paths:
  - 'app/Policies/**'
---

# App Policies

## A missing policy method is an ALLOW in Filament — define every destructive ability
Outside strict mode Filament allows an action whose policy method is missing (vendor/filament/filament/src/helpers.php). Since Phase 8.6 the panel is strict in local/test (throws) and production fails closed via AppServiceProvider's Gate::before — but still declare every ability explicitly. Before 8.6 this let any recruiter hard-delete accepted offers, interviews, joined records and force-delete candidates/requisitions. Every resource offering Delete/ForceDelete/Restore (single or bulk) must have the explicit method (tests/Unit/PolicyActionCoverageTest.php enforces it). Hiring-fact policies use Policies\Concerns\ForbidsDeletion (all false) — those facts change only through their lifecycle services.
