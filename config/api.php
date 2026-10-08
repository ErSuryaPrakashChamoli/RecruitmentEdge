<?php

/*
| SaaS-6: the tenant API, integration connections and webhooks. Every value here is a safe default
| for an owner decision (docs/saas-6-decision-register.md); none is a commercial grant — access is
| governed by the SaaS-3 entitlements api.access and integrations.webhooks.
*/
return [

    'credentials' => [
        // Credentials a tenant may hold at once (technical cap, checked under the tenant lock).
        'max_active_per_tenant' => (int) env('API_MAX_CREDENTIALS_PER_TENANT', 25),
        // Default and longest lifetime offered when issuing a credential, in days. D-S6-O3.
        'default_expiry_days' => 90,
        'max_expiry_days' => 365,
        // last_used_at is written at most this often per credential (seconds).
        'last_used_resolution' => 60,
    ],

    'rate_limits' => [
        // Requests per minute. D-S6-O4.
        'per_credential' => (int) env('API_RATE_PER_CREDENTIAL', 120),
        'per_tenant' => (int) env('API_RATE_PER_TENANT', 600),
        'per_inbound_connection' => (int) env('API_RATE_PER_INBOUND_CONNECTION', 300),
        // Failed authentications per minute from one address.
        'auth_failures_per_ip' => 30,
    ],

    'limits' => [
        // Largest request body accepted, in bytes.
        'api_body_bytes' => 64 * 1024,
        'inbound_webhook_body_bytes' => 256 * 1024,
        // Page size: default and largest.
        'per_page' => 25,
        'max_per_page' => 100,
    ],

    'telemetry' => [
        // SaaS-7 (C8): one `api.request` log line per request (route, status, duration, credential id).
        'log_requests' => (bool) env('API_LOG_REQUESTS', true),
    ],

    'idempotency' => [
        // How long a key and its response are kept for replay (hours). D-S6-O13.
        'retention_hours' => 24,
    ],

    'webhooks' => [
        // Signature timestamp tolerance (seconds), inbound and outbound — as SaaS-4 billing.
        'tolerance_seconds' => 300,
        // A rotated secret stays valid (inbound) / co-signed (outbound) this long (hours).
        'rotation_overlap_hours' => 24,
        // Delays before each retry of an outbound delivery, in seconds; then it fails. D-S6-O5.
        'retry_delays' => [60, 300, 1800, 7200, 21600, 43200],
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 10,
        // Bytes of a receiver's response kept on the delivery record.
        'response_excerpt_bytes' => 1024,
        // Ports an outbound webhook URL may use.
        'allowed_ports' => [443, 8443],
        // Connections a tenant may hold per direction (technical cap).
        'max_connections_per_direction' => 10,
        // Days webhook events, deliveries and inbound payloads are kept. D-S6-O12.
        'retention_days' => 30,
        // Inbound processing attempts before an event is marked failed.
        'inbound_attempts' => 3,
        // SaaS-7 (C6, D-S7-O11): infrastructure protection, not a commercial quota. Per tenant per
        // minute, deliveries sent and inbound events processed; the excess waits (still due) for the
        // next sweep — never dropped — so one tenant cannot hold the shared worker.
        'tenant_deliveries_per_minute' => (int) env('WEBHOOK_TENANT_DELIVERIES_PER_MINUTE', 120),
        'tenant_inbound_per_minute' => (int) env('WEBHOOK_TENANT_INBOUND_PER_MINUTE', 120),
        // An endpoint failing this many times in a row gets one trial delivery per cool-off.
        'circuit_failures' => 5,
        'circuit_cooloff_seconds' => 300,
    ],
];
