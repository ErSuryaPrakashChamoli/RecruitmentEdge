<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant #1 — the existing organisation (SaaS-1 upgrade)
    |--------------------------------------------------------------------------
    |
    | Used once, by the backfill migration, when an existing single-organisation database is
    | upgraded: its rows are assigned to this tenant. The slug appears in staff, careers and portal
    | URLs (/admin/{slug}); choose it before the upgrade, it is not derived from any request.
    |
    */

    'tenant_one' => [
        'slug' => env('TENANT_ONE_SLUG', 'main'),
        'name' => env('TENANT_ONE_NAME', env('APP_COMPANY_NAME', env('APP_NAME', 'Recruitment Edge'))),
        'legal_name' => env('TENANT_ONE_LEGAL_NAME'),
        'timezone' => env('TENANT_ONE_TIMEZONE', env('METRICS_BUSINESS_TIMEZONE', 'Asia/Kolkata')),
        'locale' => env('TENANT_ONE_LOCALE', 'en'),
        'currency' => env('TENANT_ONE_CURRENCY', 'INR'),
        'country' => env('TENANT_ONE_COUNTRY', 'IN'),
    ],

];
