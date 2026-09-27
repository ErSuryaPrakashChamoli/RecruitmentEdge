---
paths:
  - 'app/Policies/**'
---

# App Policies

## A missing policy method is an ALLOW in Filament — define every destructive ability
Filament is not in strict authorization mode: when a policy lacks delete/forceDelete/restore or deleteAny/forceDeleteAny/restoreAny, Filament allows the action even though Gate::can() returns false (vendor/filament/filament/src/helpers.php). Before 8.6 this let any recruiter hard-delete accepted offers, interviews, joined records and force-delete candidates/requisitions. Every resource offering Delete/ForceDelete/Restore (single or bulk) must have the explicit method (tests/Unit/PolicyActionCoverageTest.php enforces it). Hiring-fact policies use Policies\Concerns\ForbidsDeletion (all false) — those facts change only through their lifecycle services.
