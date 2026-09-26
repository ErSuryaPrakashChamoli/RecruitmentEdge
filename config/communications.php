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

    // Default country calling code for mobile numbers stored without one (E.164 for SMS/WhatsApp).
    'default_country_code' => env('COMMUNICATIONS_DEFAULT_COUNTRY_CODE', '91'),

    // Reminder windows for scheduled candidate reminders (communications:send-reminders).
    'interview_reminder_hours' => 24,
    'joining_reminder_days' => 2,
];
