<?php

namespace App\Models;

use Database\Factories\RecruitmentIncentiveSlabFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One achievement-% band -> flat amount (Section 24's example table). `achievement_max` of null
 * means "no upper bound" (the top, uncapped slab).
 *
 * Bands within one rule must never overlap (both ends are inclusive — see matches()), `max` must
 * exceed `min`, and only the highest band may be open-ended. That keeps
 * RecruiterIncentiveCalculator's "first matching slab" pick deterministic. bandViolation() is the
 * single check, used by the Slabs relation manager's form rules and enforced again on every save.
 */
#[Fillable(['incentive_rule_id', 'achievement_min', 'achievement_max', 'amount'])]
class RecruitmentIncentiveSlab extends Model
{
    /** @use HasFactory<RecruitmentIncentiveSlabFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (RecruitmentIncentiveSlab $slab): void {
            $violation = self::bandViolation(
                (int) $slab->incentive_rule_id,
                (float) $slab->achievement_min,
                $slab->achievement_max !== null ? (float) $slab->achievement_max : null,
                $slab->exists ? $slab->getKey() : null,
            );

            if ($violation !== null) {
                throw new DomainException($violation);
            }
        });
    }

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
     * Why a band of [$min, $max] cannot be saved on the given rule, or null when it is valid.
     * $ignoreSlabId excludes the slab being edited from the comparison.
     */
    public static function bandViolation(int $incentiveRuleId, float $min, ?float $max, ?int $ignoreSlabId = null): ?string
    {
        if ($max !== null && $max <= $min) {
            return 'The upper bound must be greater than the lower bound.';
        }

        $siblings = self::query()
            ->where('incentive_rule_id', $incentiveRuleId)
            ->when($ignoreSlabId !== null, fn ($query) => $query->whereKeyNot($ignoreSlabId))
            ->get();

        if ($max === null && $siblings->contains(fn (RecruitmentIncentiveSlab $slab) => $slab->achievement_max === null)) {
            return 'This rule already has an open-ended slab; only one slab may have no upper bound.';
        }

        foreach ($siblings as $sibling) {
            $siblingMin = (float) $sibling->achievement_min;
            $siblingMax = $sibling->achievement_max !== null ? (float) $sibling->achievement_max : null;

            if ($max === null && $siblingMin >= $min) {
                return 'An open-ended slab must be the highest band; a slab starting at '.number_format($siblingMin, 2).'% already exists above it.';
            }

            if ($siblingMax === null && $min >= $siblingMin) {
                return 'The open-ended slab starting at '.number_format($siblingMin, 2).'% must remain the highest band.';
            }

            $overlaps = $min <= ($siblingMax ?? INF) && $siblingMin <= ($max ?? INF);

            if ($overlaps) {
                return 'This band overlaps the existing '.number_format($siblingMin, 2).'% – '
                    .($siblingMax !== null ? number_format($siblingMax, 2).'%' : 'uncapped').' slab (both bounds are inclusive).';
            }
        }

        return null;
    }

    /**
     * @return BelongsTo<RecruitmentIncentiveRule, $this>
     */
    public function incentiveRule(): BelongsTo
    {
        return $this->belongsTo(RecruitmentIncentiveRule::class, 'incentive_rule_id');
    }
}
