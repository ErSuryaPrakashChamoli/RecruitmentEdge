<?php

namespace App\Services\Api;

use App\Models\ApiCredential;
use App\Models\Tenant;
use App\Models\User;

/**
 * SaaS-6: who an authenticated API request is — the credential, the member it acts as, and the
 * tenant it belongs to (always the credential's own; never anything the request names).
 */
final readonly class ApiPrincipal
{
    public function __construct(
        public ApiCredential $credential,
        public User $owner,
        public Tenant $tenant,
    ) {}
}
