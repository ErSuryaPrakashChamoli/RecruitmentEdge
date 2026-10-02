<?php

namespace App\Http\Session;

use App\Http\Middleware\UseCandidateSessionContext;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Session\DatabaseSessionHandler;

/**
 * Phase 8.9 (P89-SEC-007): staff and candidate sessions share the `sessions` table, but only a staff
 * session records its user in `user_id`. On candidate paths (UseCandidateSessionContext makes
 * `candidate` the default guard) the column stays null — previously it held the candidate-account
 * id, so a staff "sign out everywhere" (SessionRevocationService deletes by user_id) could end an
 * unrelated candidate's session and the Access Review session counts included candidates. No guard
 * is consulted in candidate context, so a staff remember-me cookie is never read there (D8.8-001).
 */
class StaffDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function userId()
    {
        if ($this->container->make('auth')->getDefaultDriver() === UseCandidateSessionContext::CANDIDATE_GUARD) {
            return null;
        }

        return $this->container->make(Guard::class)->id();
    }
}
