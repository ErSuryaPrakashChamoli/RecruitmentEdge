<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Production readiness (PR-01): only the public API is reachable cross-origin. It authenticates
| with bearer credentials and never with cookies, so `supports_credentials` stays false: a browser
| on another origin can call it only with a credential it already holds. The panels, the portal and
| the career site are same-origin and send no CORS headers.
|
| CORS_ALLOWED_ORIGINS — comma-separated origins (https://app.example.com) allowed to call the API
| from a browser. Unset: any origin (the framework default, unchanged). Empty: no browser origin.
| Which origins is part of the API exposure decision (D-S6-O1); ops:preflight warns while any
| origin is allowed.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
