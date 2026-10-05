<?php

/*
 * SaaS-4: commercial subscription billing (docs/saas-4-billing.md). Every commercial value here is
 * a configurable default pending an owner decision (docs/saas-4-decision-register.md): the
 * production payment provider, the currencies offered, the grace period and the invoice series.
 * Provider credentials are platform infrastructure secrets read from the environment only.
 */
return [

    /*
     * The payment provider adapter (App\Services\Billing\Providers). Only "fake" exists today: a
     * deterministic development/test adapter that is refused outside local and testing.
     */
    'provider' => env('BILLING_PROVIDER', 'fake'),

    'providers' => [
        'fake' => [
            'webhook_secret' => env('BILLING_FAKE_WEBHOOK_SECRET'),
        ],
    ],

    /*
     * Currencies a price or invoice may use, with their minor-unit precision (ISO 4217). Amounts
     * are stored as integers in minor units of their own currency; nothing is ever converted.
     */
    'currencies' => [
        'INR' => 2,
        'USD' => 2,
    ],

    /*
     * Days a subscription stays past due (the tenant usable, flagged) after a failed payment before
     * access ends and the subscription becomes unpaid. Owner decision D-S4-O4.
     */
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    /*
     * The platform's single invoice series: {prefix}/{financial year}/{number}, gap-free per
     * financial year. year_starts_month 4 = the Indian financial year (April–March). Owner decision.
     */
    /*
     * Hours between automatic collection attempts of an unpaid invoice while the grace period runs
     * (a provider may retry on its own as well). Owner decision D-S4-O4 (dunning schedule).
     */
    'collection_retry_hours' => (int) env('BILLING_COLLECTION_RETRY_HOURS', 24),

    'invoice_number' => [
        'prefix' => env('BILLING_INVOICE_PREFIX', 'RE'),
        'year_starts_month' => (int) env('BILLING_INVOICE_YEAR_STARTS_MONTH', 4),
    ],

    'webhooks' => [
        // A signed event older (or further in the future) than this is refused as a replay.
        'tolerance_seconds' => 300,
        // Raw payloads are kept this long for reprocessing and reconciliation, then cleared.
        'payload_retention_days' => (int) env('BILLING_WEBHOOK_PAYLOAD_RETENTION_DAYS', 90),
        // Queue for event processing. SaaS-7 (C4): their own queue, on the priority worker — no
        // longer behind AI, document conversion and tenant webhook volume on `integrations`.
        'queue' => 'billing',
    ],
];
