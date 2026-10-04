<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * SaaS-4: an event names a payment not recorded yet (the provider answered faster than our own
 * write): processing is retried with backoff before the event is set aside.
 */
final class BillingReferencePending extends RuntimeException {}
