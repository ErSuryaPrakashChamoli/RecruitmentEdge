<?php

/*
|--------------------------------------------------------------------------
| Candidate Communications (Phase 5)
|--------------------------------------------------------------------------
|
| Which provider adapter serves each channel, and how outbound messages are queued and retried.
| Provider credentials live in config/services.php (from the environment).
|
*/

return [
    'providers' => [
        'email' => env('COMMUNICATIONS_EMAIL_PROVIDER', 'mail'),
        'whatsapp' => env('COMMUNICATIONS_WHATSAPP_PROVIDER', 'whatsapp_cloud'),
        'sms' => env('COMMUNICATIONS_SMS_PROVIDER', 'twilio'),
    ],

    'queue' => env('COMMUNICATIONS_QUEUE', 'communications'),

    // Attempts per message and the waits (seconds) between them for temporary provider failures.
    'tries' => 5,
    'backoff' => [30, 120, 600, 1800],

    // Phase 8.7 (D8.7-019): after `threshold` temporary failures within `window` seconds a provider
    // is paused for `cooldown` seconds; its messages stay Queued and are re-queued afterwards.
    'circuit' => [
        'threshold' => (int) env('COMMUNICATIONS_CIRCUIT_THRESHOLD', 5),
        'window' => 300,
        'cooldown' => (int) env('COMMUNICATIONS_CIRCUIT_COOLDOWN', 300),
    ],

    // Phase 8.7 (D8.7-013): reliability:sweep re-queues messages held Queued longer than
    // `requeue_after_minutes` and fails messages stuck Sending longer than `stuck_sending_minutes`.
    'recovery' => [
        'requeue_after_minutes' => 10,
        // Phase 8.9 (P89-PERF-007): a bound on how many held messages one five-minute sweep re-queues.
        'requeue_max_per_run' => (int) env('COMMUNICATIONS_REQUEUE_MAX_PER_RUN', 1000),
        'stuck_sending_minutes' => 30,
    ],

    // Default country calling code for mobile numbers stored without one (E.164 for SMS/WhatsApp).
    'default_country_code' => env('COMMUNICATIONS_DEFAULT_COUNTRY_CODE', '91'),

    // Reminder windows for scheduled candidate reminders (communications:send-reminders).
    'interview_reminder_hours' => 24,
    'joining_reminder_days' => 2,
];
