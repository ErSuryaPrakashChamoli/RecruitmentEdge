<?php

namespace App\Services\Identity;

use DomainException;

/**
 * Phase 8.4: a change would have left the organisation without an effective CHRO. Callers record
 * the refused attempt (AuthorityGuard::recordProtection) after their transaction has rolled back.
 */
class LastChroProtectedException extends DomainException {}
