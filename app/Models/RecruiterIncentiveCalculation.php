<?php

namespace App\Models;

use App\Enums\IncentiveBeneficiary;
use App\Enums\IncentiveCalculationStatus;
use App\Enums\IncentivePayoutType;
use Database\Factories\RecruiterIncentiveCalculationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The traceable unit of the incentive engine (Section 31): one row per recruiter/rule/application/
 * period. `amount` is the originally calculated figure and is never edited in place once the
 * calculation leaves Calculated/PendingVerification — corrections after that point are
 * RecruiterIncentiveAdjustment rows; see effectiveAmount().
 *
 * `beneficiary_type` (Phase 4.1) says who the money is for: the application's recruiter, or — for
 * ReferralJoining rules — the referring employee (`employee_referral_id` then links the referral).
 * Recruiter-facing views (scorecards, team totals, statements) use scopeForRecruiters().
 */
#[Fillable([
    'incentive_rule_id',
    'incentive_slab_id',
    'employee_id',
    'beneficiary_type',
    'candidate_id',
    'candidate_application_id',
    'employee_referral_id',
    'period_start',
    'period_end',
    'achievement',
    'occurrence_count',
    'amount',
    'pricing_snapshot',
    'status',
    'retention_due_at',
    'calculated_at',
    'created_by',
])]
class RecruiterIncentiveCalculation extends Model
{
    /** @use HasFactory<RecruiterIncentiveCalculationFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'beneficiary_type' => 'recruiter',
    ];

    protected function casts(): array
    {
        return [
            'status' => IncentiveCalculationStatus::class,
            'pricing_snapshot' => 'array',
            'beneficiary_type' => IncentiveBeneficiary::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'retention_due_at' => 'date',
            'achievement' => 'decimal:2',
            'occurrence_count' => 'integer',
            'amount' => 'decimal:2',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * The amount after every adjustment/reversal is applied — the figure that should actually be
     * paid or reported, while `amount` preserves the original calculation forever.
     */
    public function effectiveAmount(): float
    {
        // Phase 8.9 (P89-PERF-022): lists preload the sum (withAdjustmentTotal) or the adjustments
        // instead of one query per row.
        $adjustmentTotal = match (true) {
            array_key_exists('adjustments_sum_amount_delta', $this->attributes) => $this->attributes['adjustments_sum_amount_delta'],
            $this->relationLoaded('adjustments') => $this->adjustments->sum('amount_delta'),
            default => $this->adjustments()->sum('amount_delta'),
        };

        return (float) $this->amount + (float) $adjustmentTotal;
    }

    /**
     * The figure the slab was matched on: the occurrence count for a Slab-by-count rule, the
     * achievement % otherwise. Null when the calculation was not slab-priced on a figure.
     */
    public function slabBasis(): ?float
    {
        $value = $this->incentiveRule?->payout_type === IncentivePayoutType::SlabByCount
            ? $this->occurrence_count
            : $this->achievement;

        return $value !== null ? (float) $value : null;
    }

    /**
     * @return BelongsTo<RecruitmentIncentiveRule, $this>
     */
    public function incentiveRule(): BelongsTo
    {
        return $this->belongsTo(RecruitmentIncentiveRule::class, 'incentive_rule_id');
    }

    /**
     * @return BelongsTo<RecruitmentIncentiveSlab, $this>
     */
    public function incentiveSlab(): BelongsTo
    {
        return $this->belongsTo(RecruitmentIncentiveSlab::class, 'incentive_slab_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        // Phase 8.4: a deleted employee's records keep their attribution (and stay visible to the
        // managers above them) — historical ownership is never silently dropped.
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return HasMany<RecruiterIncentiveAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(RecruiterIncentiveAdjustment::class)->latest('created_at');
    }

    /**
     * @return HasMany<RecruiterIncentiveApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(RecruiterIncentiveApproval::class)->latest('created_at');
    }

    /**
     * @return HasMany<RecruiterIncentivePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(RecruiterIncentivePayment::class);
    }

    /**
     * @return BelongsTo<EmployeeReferral, $this>
     */
    public function employeeReferral(): BelongsTo
    {
        return $this->belongsTo(EmployeeReferral::class);
    }

    /**
     * @param  Builder<RecruiterIncentiveCalculation>  $query
     */
    #[Scope]
    protected function forBeneficiary(Builder $query, IncentiveBeneficiary $beneficiary): void
    {
        $query->where('beneficiary_type', $beneficiary);
    }

    /**
     * Phase 8.9 (P89-PERF-022): preloads the adjustment total effectiveAmount() reads, for lists.
     *
     * @param  Builder<RecruiterIncentiveCalculation>  $query
     */
    #[Scope]
    protected function withAdjustmentTotal(Builder $query): void
    {
        $query->withSum('adjustments', 'amount_delta');
    }

    /**
     * Recruiter incentives only — excludes employee referral bonuses.
     *
     * @param  Builder<RecruiterIncentiveCalculation>  $query
     */
    #[Scope]
    protected function forRecruiters(Builder $query): void
    {
        $query->where('beneficiary_type', IncentiveBeneficiary::Recruiter);
    }

    /**
     * Phase 8.6 (D8.6-014): whether the rule/slab parameters this calculation was priced with were
     * recorded (calculations priced before 8.6 were not).
     */
    public function hasPricingSnapshot(): bool
    {
        return is_array($this->pricing_snapshot) && isset($this->pricing_snapshot['rule']);
    }

    /**
     * The rule name as priced, or today's name (marked) when the pricing was not recorded.
     */
    public function pricedRuleName(): string
    {
        return $this->hasPricingSnapshot()
            ? (string) $this->pricing_snapshot['rule']['name']
            : ($this->incentiveRule?->name ?? '—');
    }

    public function pricedPayoutLabel(): string
    {
        $type = $this->hasPricingSnapshot() ? IncentivePayoutType::tryFrom((string) $this->pricing_snapshot['rule']['payout_type']) : $this->incentiveRule?->payout_type;

        return $type?->label() ?? '—';
    }

    /**
     * The slab band as priced; for an unrecorded pricing, the current band marked "(current rule)"
     * so it is never mistaken for what was applied.
     */
    public function pricedBandLabel(): ?string
    {
        if ($this->hasPricingSnapshot()) {
            return $this->pricing_snapshot['slab']['band'] ?? null;
        }

        $band = $this->incentiveSlab?->bandLabel($this->incentiveRule);

        return $band !== null ? "{$band} (current rule)" : null;
    }

    public function pricedSlabAmount(): ?float
    {
        if ($this->hasPricingSnapshot()) {
            return isset($this->pricing_snapshot['slab']['amount']) ? (float) $this->pricing_snapshot['slab']['amount'] : null;
        }

        return $this->incentiveSlab !== null ? (float) $this->incentiveSlab->amount : null;
    }
}
