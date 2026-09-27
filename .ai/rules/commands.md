---
paths:
  - 'routes/console.php,app/Console/Commands/**'
---

# Commands

## Scheduled tasks: one scheduler, overlap guards, per-item isolation
Exactly one scheduler (docker `scheduler` service or one cron schedule:run, never both). Every Schedule::command gets ->withoutOverlapping(<minutes sized to the task>)->onOneServer(); long tasks ->runInBackground() — SchedulerReliabilityTest enforces it. Loops over records wrap each item in try/catch + report() so one bad row never stops the pass. Outcomes feed SchedulerHeartbeat (Queue health page, queue:health-check). The schedule is only loaded by the console kernel: web code must read it via SchedulerHeartbeat::tasks(), never app(Schedule::class) directly (empty in HTTP; feature tests can't see this).
