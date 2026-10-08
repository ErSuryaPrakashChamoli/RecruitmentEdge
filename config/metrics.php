<?php

/*
|--------------------------------------------------------------------------
| Metric governance (Phase 8.5)
|--------------------------------------------------------------------------
|
| Every governed number comes from a registered MetricDefinition (app/Services/Metrics). These
| settings are the shared primitives the definitions read. See docs/phase-8-5-metric-governance.md.
|
*/

return [
    // D16: calendar days, weeks and months of every period metric are cut in this timezone. Storage
    // stays UTC; only the day boundaries move.
    'business_timezone' => env('METRICS_BUSINESS_TIMEZONE', 'Asia/Kolkata'),

    // D23: below this many observations a governed metric withholds its value (status
    // insufficient_sample, value null, n still reported). A definition may override it.
    'min_sample' => 3,

    // D22: an aggregate of compensation figures is withheld below this group size, whatever the
    // metric's own sample rule.
    'compensation_min_group' => 5,

    // D14: seconds a cacheable period metric result is kept (keyed by metric, version, viewer scope,
    // period and filters). 0 disables caching.
    'cache_ttl' => (int) env('METRICS_CACHE_TTL', 600),
];
