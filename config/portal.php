<?php

/*
|--------------------------------------------------------------------------
| Candidate Portal Security (Phase 8.8, D8.8-001)
|--------------------------------------------------------------------------
|
| The approved candidate authentication model: password first, with an optional email one-time
| code ("step-up") that sensitive portal actions can require. The values below marked APPROVED
| come from decision D8.8-001 and must not change without a new decision.
|
*/

return [
    'step_up' => [
        // APPROVED (D8.8-001): a code is valid for 10 minutes and allows 5 verification attempts.
        'code_ttl_minutes' => 10,
        'max_attempts' => 5,

        // Engineering defaults (brute-force protection required by D8.8-001): at most this many codes
        // per candidate per window. With 5 attempts each, at most 15 guesses per 10 minutes against
        // a 1-in-1,000,000 code.
        'issue_limit' => 3,
        'issue_window_minutes' => 10,

        // Engineering default: how long a verified step-up satisfies a route that requires it, unless
        // the route passes its own window. No route requires step-up yet (D8.8-001).
        'fresh_for_minutes' => 15,

        // Purposes a code can be issued for. A code verifies only the purpose it was issued for.
        'purposes' => ['sensitive_action'],
    ],
];
