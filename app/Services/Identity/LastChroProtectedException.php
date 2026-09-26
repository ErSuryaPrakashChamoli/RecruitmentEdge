<?php

namespace App\Services\Identity;

use App\Models\User;
use DomainException;

/**
 * Phase 8.4: a change would have left the organisation without an effective CHRO. Callers record
 * the refused attempt through AuthorityGuard::protecting(), after everything has rolled back.
 */
class LastChroProtectedException extends DomainException
{
    public function __construct(string $message, public readonly ?User $user = null)
    {
        parent::__construct($message);
    }
}
