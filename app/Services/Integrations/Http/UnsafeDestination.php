<?php

namespace App\Services\Integrations\Http;

use DomainException;

/**
 * SaaS-6: a URL the platform will not call (OutboundUrlGuard). The message is safe to show.
 */
class UnsafeDestination extends DomainException {}
