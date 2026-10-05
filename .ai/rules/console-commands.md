---
paths:
  - 'app/Console/Commands/SweepStuckWork.php,config/communications.php'
  - 'app/Console/Commands/**'
---

# Console Commands

## The reliability sweep re-queues a bounded number per run
Phase 8.9 (P89-PERF-007): reliability:sweep re-dispatches at most communications.recovery.requeue_max_per_run (COMMUNICATIONS_REQUEUE_MAX_PER_RUN, default 1000) held Queued messages per run, oldest first. Messages of a paused provider are skipped and don't count. A larger backlog is drained by the workers, not re-dispatched in full every five minutes. Never turn this back into an O(backlog) loop: SendCommunicationJob is unique per message, so re-dispatching only repeats lock checks.

## Queue unique jobs through a PendingDispatch, never Bus::dispatch
Bus::dispatch(new Job) skips ShouldBeUnique entirely (only PendingDispatch takes the unique lock). tenants:dispatch therefore queues RunTenantScheduledTask with `Job::dispatch()` as a statement inside a void closure passed to TenantContext::run (so the PendingDispatch is released inside the tenant, not returned and queued after the context is restored). A tenant pass (tenants:run) must re-read each tenant with a shared lock right before its work and skip it unless allowsBackgroundWork().
