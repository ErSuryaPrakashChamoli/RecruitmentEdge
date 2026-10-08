<?php

namespace App\Services\Identity;

use DomainException;

/**
 * SaaS-2: an invitation that cannot be accepted. The message shown is deliberately the same for
 * most reasons (revoked, expired, used, its tenant unusable, its roles or inviter changed); the
 * reason is recorded for the tenant's audit and the log only.
 */
class InvitationUnavailable extends DomainException
{
    public function __construct(
        public readonly string $reason = 'unavailable',
        string $message = 'This invitation is no longer valid. Ask the organisation to send you a new one.',
    ) {
        parent::__construct($message);
    }
}
