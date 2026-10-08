<?php

/*
|--------------------------------------------------------------------------
| Recruitment Automation (Phase 6)
|--------------------------------------------------------------------------
|
| Safety limits and batch sizes for the automation engine. Per-rule limits (cooldown, daily cap,
| per-entity cap) are configured on each rule; these are the global backstops that stop a bad rule
| from flooding people, whatever its own settings.
|
*/

return [
    'queue' => env('AUTOMATION_QUEUE', 'automation'),

    // An automation whose action triggers another automation is a chain; beyond this depth the
    // chain is stopped and audited (loop prevention).
    'max_chain_depth' => 3,

    // Global backstop per rule per day, applied even when the rule sets no daily limit.
    'max_executions_per_rule_per_day' => (int) env('AUTOMATION_MAX_EXECUTIONS_PER_RULE_PER_DAY', 500),

    // Automated (rule-sent) candidate messages per candidate per day, across all rules.
    'max_candidate_messages_per_day' => (int) env('AUTOMATION_MAX_CANDIDATE_MESSAGES_PER_DAY', 3),

    // Batch sizes for the scheduled commands — never unbounded.
    'dispatch_limit_per_rule' => 500,
    'process_limit' => 200,

    // A run stuck in "running" this long is marked failed (worker died mid-run).
    'stale_running_minutes' => 60,

    // Pending time-based runs this far past due are cancelled instead of run late.
    'stale_pending_days' => 7,

    // Open Action Center items this many days past due become Expired.
    'action_expiry_days' => 14,

    // Executions skipped because conditions were not met are pruned after this many days.
    'prune_skipped_after_days' => 90,
];
