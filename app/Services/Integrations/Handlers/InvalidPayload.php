<?php

namespace App\Services\Integrations\Handlers;

use DomainException;

/**
 * SaaS-6: an inbound event whose content can never be processed (wrong type, invalid data) — marked
 * Ignored instead of being retried.
 */
class InvalidPayload extends DomainException {}
