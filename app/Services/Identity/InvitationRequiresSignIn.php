<?php

namespace App\Services\Identity;

use DomainException;

/**
 * SaaS-2: the invited address already belongs to an identity — the person signs in with it to
 * accept, and no second identity is ever created. Only the holder of a valid invitation link for
 * that address ever sees this.
 */
class InvitationRequiresSignIn extends DomainException
{
    public function __construct()
    {
        parent::__construct('An account with this email address already exists. Sign in with it to accept the invitation.');
    }
}
