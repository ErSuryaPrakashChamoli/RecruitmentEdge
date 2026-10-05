<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS-6: one API mutation's idempotency record — unique per (tenant, credential, key). Holds the
 * request fingerprint and, once completed, the response to replay (encrypted). Written only by
 * IdempotencyService.
 */
#[Fillable(['api_credential_id', 'idempotency_key', 'method', 'route', 'request_hash', 'status', 'response_status', 'response_encrypted', 'expires_at'])]
#[Hidden(['response_encrypted'])]
class ApiIdempotencyKey extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'response_encrypted' => 'encrypted:array',
            'expires_at' => 'datetime',
        ];
    }
}
