<?php

namespace App\Models;

use App\Enums\RediscoveryResultStatus;
use App\Enums\SignalBand;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One rediscovered candidate: rank, band, why (summary + evidence) and what a person decided.
 */
#[Fillable(['rediscovery_run_id', 'candidate_id', 'rank', 'band', 'required_coverage_pct', 'summary', 'do_not_contact'])]
class RediscoveryResult extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'suggested',
    ];

    protected function casts(): array
    {
        return [
            'band' => SignalBand::class,
            'status' => RediscoveryResultStatus::class,
            'summary' => 'array',
            'do_not_contact' => 'boolean',
            'required_coverage_pct' => 'float',
            'actioned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RediscoveryRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RediscoveryRun::class, 'rediscovery_run_id');
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    /**
     * @return MorphMany<IntelligenceEvidence, $this>
     */
    public function evidence(): MorphMany
    {
        return $this->morphMany(IntelligenceEvidence::class, 'owner');
    }
}
