<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Collection;

/**
 * Thrown when a workflow would create a Candidate Master record that strongly matches existing
 * ones and no justification was given. Carries the matches so the caller can offer "use existing
 * candidate" instead.
 */
class DuplicateCandidateFoundException extends DomainException
{
    /**
     * @param  Collection<int, DuplicateCandidateMatch>  $matches
     */
    public function __construct(public readonly Collection $matches)
    {
        parent::__construct('This candidate already exists. Use the existing candidate, or give a justification to create a new record.');
    }
}
