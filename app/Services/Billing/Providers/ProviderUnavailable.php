<?php

namespace App\Services\Billing\Providers;

use RuntimeException;

/**
 * SaaS-4: the provider could not be reached or answered with an error. Nothing is assumed about
 * the operation's outcome: local state stays as it was until an event or reconciliation tells.
 */
final class ProviderUnavailable extends RuntimeException {}
