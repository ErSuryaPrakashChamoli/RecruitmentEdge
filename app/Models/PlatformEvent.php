<?php

namespace App\Models;

use App\Enums\PlatformEventSeverity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS-5: one operational event for platform operators — a provisioning failure, a lifecycle change,
 * a support request or expiry, a deletion step. Platform record: tenant_id is the tenant the event
 * is about (empty for platform-wide events). Recorded once per dedupe_key (PlatformEvents).
 */
#[Fillable(['type', 'severity', 'tenant_id', 'title', 'context', 'dedupe_key', 'occurred_at'])]
class PlatformEvent extends Model
{
    protected function casts(): array
    {
        return [
            'severity' => PlatformEventSeverity::class,
            'context' => 'array',
            'occurred_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
