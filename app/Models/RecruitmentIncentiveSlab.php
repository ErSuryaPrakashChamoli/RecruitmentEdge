<?php

namespace App\Models;

use Database\Factories\RecruitmentIncentiveSlabFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One achievement-% band -> flat amount (Section 24's example table). `achievement_max` of null
 * means "no upper bound" (the top, uncapped slab).
 */
#[Fillable(['incentive_rule_id', 'achievement_min', 'achievement_max', 'amount'])]
class RecruitmentIncentiveSlab extends Model
{
    /** @use HasFactory<RecruitmentIncentiveSlabFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'achievement_min' => 'decimal:2',
            'achievement_max' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function matches(float $achievement): bool
    {
        return $achievement >= (float) $this->achievement_min
            && ($this->achievement_max === null || $achievement <= (float) $this->achievement_max);
    }

    /**
     * @return BelongsTo<RecruitmentIncentiveRule, $this>
     */
    public function incentiveRule(): BelongsTo
    {
        return $this->belongsTo(RecruitmentIncentiveRule::class, 'incentive_rule_id');
    }
}
