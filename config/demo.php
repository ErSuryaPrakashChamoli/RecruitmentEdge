<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | Turns this installation into a disposable client-presentation copy of Recruitment Edge:
    | enables `php artisan demo:setup` (which DELETES all data and seeds the demo story), shows
    | the demo accounts on the login page and a "Demo" badge in the top bar. Never enable it on a
    | real installation.
    |
    */

    'enabled' => (bool) env('APP_DEMO', false),

    // The password shared by every seeded demo account.
    'password' => env('DEMO_PASSWORD', 'Demo@123'),

    // Multiplies the volume of seeded recruitment activity (1 = the full demo; tests use a fraction).
    'scale' => (float) env('DEMO_SEED_SCALE', 1),

    // Fixed so every reset tells the same story; the dates always move with "today".
    'random_seed' => (int) env('DEMO_RANDOM_SEED', 20260921),

];
