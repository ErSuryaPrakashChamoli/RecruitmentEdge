<?php

namespace App\Models;

use App\Enums\HealthStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Hiring Health™ of one requisition at one point in time (Phase 7). `metrics` keeps every metric
 * exactly as evaluated: {key, label, value, display, threshold, status, unit, explanation}.
 * Written only by HiringHealthService; history is never recomputed.
 */
#[Fillable(['requisition_id', 'status', 'rules_version', 'metrics', 'breach_count', 'watch_count', 'completeness_pct', 'is_current', 'computed_at'])]
class HiringHealthSnapshot extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => HealthStatus::class,
            'metrics' => 'array',
            'is_current' => 'boolean',
            'completeness_pct' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metric(string $key): ?array
    {
        return collect($this->metrics ?? [])->firstWhere('key', $key);
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return MorphMany<IntelligenceEvidence, $this>
     */
    public function evidence(): MorphMany
    {
        return $this->morphMany(IntelligenceEvidence::class, 'owner');
    }
}
