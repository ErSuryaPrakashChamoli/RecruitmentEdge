---
paths:
  - 'app/Services/Operations/**'
  - app/Services/Operations/ProductionPreflight.php
---

# Operations

## ops:verify-integrity stays read-only; failures vs warnings
IntegrityVerifier (ops:verify-integrity) never writes, repairs or deletes: PostRestoreIntegrityTest captures every statement with DB::listen and fails on any insert/update/delete/replace/alter/create/drop/truncate. A new SaaS check goes into `failures` only for a state no service can produce (it fails the restore); anything an operator should merely look at goes into `warnings` (never fails). Counts only, never row contents. It reads plan assignments, so it is allow-listed in CommercialArchitectureTest (metadata, never a gate). Tests assert the driver via DB::connection()->getDriverName(), never a literal — the suite also runs on MySQL.

## Preflight checks declare a scope; the APP_ENV tier sets the level
Every ops:preflight check is added with a scope: 'all' (blocker in production and staging, warning in development), 'production' (blocker in production only), or 'advice' (always a warning). problems() maps scope → level by tier() (production / staging / anything else = development). Never make a development machine fail for missing infrastructure; never downgrade an existing production blocker. Messages name settings, never their values. Only the production entrypoint enforces blockers (PREFLIGHT_ENFORCE=false is a recorded emergency override).
