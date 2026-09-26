<?php

/*
|--------------------------------------------------------------------------
| Outcome Loop™ (Phase 8.2)
|--------------------------------------------------------------------------
|
| Deterministic outcomes of completed hiring. See docs/phase-8-2-outcome-loop.md.
|
*/

return [
    // Status-observation checkpoints after joining (days). Each must match an OutcomeType
    // STATUS_OBSERVED_* case. Retention is observed going forward only — never backfilled.
    'status_observation_days' => [30, 90, 180],

    // Sample-size bands. Fewer than insufficient_below comparable outcomes is "insufficient
    // history" (the same threshold as Role DNA, RoleDnaBuilder::MIN_HISTORY) and never produces
    // a learning suggestion; from stronger_from up it is a "stronger historical basis".
    'sample' => [
        'insufficient_below' => 3,
        'stronger_from' => 10,
    ],

    // Rows processed per chunk by outcomes:evaluate and outcomes:backfill.
    'batch_size' => 200,

    // Queue for outcome capture and AI narration (the existing intelligence queue).
    'queue' => env('OUTCOMES_QUEUE', 'intelligence'),
];
