---
paths:
  - 'routes/console.php,app/Console/Commands/**'
---

# Commands

## Scheduled tasks: one scheduler, overlap guards, per-item isolation
Exactly one scheduler (docker `scheduler` service or one cron schedule:run, never both). Every Schedule::command gets ->withoutOverlapping(<minutes sized to the task>)->onOneServer(); long tasks ->runInBackground() — SchedulerReliabilityTest enforces it. Loops over records wrap each item in try/catch + report() so one bad row never stops the pass. Outcomes feed SchedulerHeartbeat (Queue health page, queue:health-check). The schedule is only loaded by the console kernel: web code must read it via SchedulerHeartbeat::tasks(), never app(Schedule::class) directly (empty in HTTP; feature tests can't see this).

## Housekeeping touches only technical rows; storage:audit is read-only
Phase 8.9 (P89-OPS-009, ED-08). The schedule prunes only what has no legal meaning:
- cache rows that have already expired (cache:prune-expired);
- expired password-reset tokens (auth:clear-resets);
- finished job batches older than the failed-job window (queue:prune-batches);
- limit-skipped automation runs.

Nothing with business or audit meaning may be pruned until the retention phase (SEC-88-02 is deferred). That covers audit_logs, exports, intelligence snapshots and documents.

storage:audit only reports: size per area, unreferenced files and records with a missing file. It never deletes. Add a new file-path column to StorageAudit::REFERENCES, or its files will be reported as orphans.
