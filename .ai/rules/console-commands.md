---
paths:
  - 'app/Console/Commands/SweepStuckWork.php,config/communications.php'
---

# Console Commands

## The reliability sweep re-queues a bounded number per run
Phase 8.9 (P89-PERF-007): reliability:sweep re-dispatches at most communications.recovery.requeue_max_per_run (COMMUNICATIONS_REQUEUE_MAX_PER_RUN, default 1000) held Queued messages per run, oldest first. Messages of a paused provider are skipped and don't count. A larger backlog is drained by the workers, not re-dispatched in full every five minutes. Never turn this back into an O(backlog) loop: SendCommunicationJob is unique per message, so re-dispatching only repeats lock checks.
