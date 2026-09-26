<?php

/*
|--------------------------------------------------------------------------
| EDGE Intelligence (Phase 7)
|--------------------------------------------------------------------------
|
| Bounds and freshness for the intelligence layer. Everything here is deterministic except the
| optional AI contributions, which run on their own queue and never block recruitment.
|
*/

return [
    'queue' => env('INTELLIGENCE_QUEUE', 'intelligence'),

    'rediscovery' => [
        // Most recently active candidates scanned per run — never the whole database.
        'max_scan' => (int) env('INTELLIGENCE_REDISCOVERY_MAX_SCAN', 2000),
        'max_results' => 25,
    ],

    'refresh' => [
        // Open requisitions refreshed per scheduled run, and signals refreshed per requisition.
        'requisitions_per_run' => 200,
        'signals_per_requisition' => 200,
    ],

    'ai' => [
        // Hard limits applied to AI suggestions before anything is stored.
        'max_items_per_list' => 12,
        'max_item_length' => 80,
        // A request still "processing" after this long (e.g. no worker, lost job) is marked failed
        // by intelligence:refresh so it can be requested again.
        'stale_after_minutes' => 60,
    ],
];
