<?php

namespace App\Services\AI\Privacy;

use RuntimeException;

/**
 * Thrown by AiEgressGuard in `block` mode (tests, strict environments) when a provider-bound
 * payload still contains personal data. The message names the kinds found, never the values.
 */
class AiPrivacyViolationException extends RuntimeException {}
