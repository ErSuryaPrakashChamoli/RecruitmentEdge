<?php

namespace App\Models;

use App\Enums\IncentiveCalculationStatus;
use Database\Factories\RecruiterIncentiveCalculationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The traceable unit of the incentive engine (Section 31): one row per recruiter/rule/application/
 * period. `amount` is the originally calculated figure and is never edited in place once the
 * calculation leaves Calculated/PendingVerification — corrections after that point are
 * RecruiterIncentiveAdjustment rows; see effectiveAmount().
 */
#[Fillable([
    'incentive_rule_id',
    'incentive_slab_id',
    'employee_id',
    'candidate_id',
    'candidate_application_id',
    'period_start',
    'period_end',
    'achievement',
    'amount',
    'status',
    'retention_due_at',
    'calculated_at',
    'created_by',
])]
class RecruiterIncentiveCalculation extends Model
{
    /** @use HasFactory<RecruiterIncentiveCalculationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => IncentiveCalculationStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'retention_due_at' => 'date',
            'achievement' => 'decimal:2',
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
        return (float) $this->amount + (float) $this->adjustments()->sum('amount_delta');
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
        return $this->belongsTo(Employee::class);
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
}
