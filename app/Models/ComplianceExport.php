<?php

namespace App\Models;

use App\Enums\ComplianceExportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * SaaS-5: one compliance export of a tenant's data — the platform's register of what was exported,
 * for which tenant, by whom, why, when, its checksum and when its artifact expires (the artifact is
 * deleted then; the register entry stays). Written only by ComplianceExportService.
 */
#[Fillable(['tenant_id', 'status', 'reason', 'requested_by', 'requested_at', 'disk'])]
class ComplianceExport extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('An export register entry is never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => ComplianceExportStatus::class,
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'expired_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
            'manifest' => 'array',
            'bytes' => 'integer',
            'download_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
