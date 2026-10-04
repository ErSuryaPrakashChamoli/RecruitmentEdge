<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\IncentivePayoutType;
use App\Enums\IncentiveSlabUpgradeMode;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\TargetMetric;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\ReferencesActiveMasterData;
use Database\Factories\RecruitmentIncentiveRuleFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Section 24: incentive rules are scoped by any combination of recruiter/department/designation/
 * location/employment type (all nullable — omit a scope to apply it broadly) and fire on a
 * configurable trigger event. `payout_type` decides the amount per occurrence: a `fixed_amount`, or
 * the RecruitmentIncentiveSlab band matching the recruiter's occurrence count / achievement on
 * `achievement_metric` for the month, with `slab_upgrade_mode` deciding whether a higher band also
 * re-prices the month's earlier occurrences. `retention_days`, when set, delays a calculation's move
 * out of Calculated until that many days after the triggering fact (Section 26).
 */
#[Fillable([
    'name',
    'trigger_event',
    'achievement_metric',
    'payout_type',
    'fixed_amount',
    'slab_upgrade_mode',
    'employee_id',
    'department_id',
    'designation_id',
    'location_id',
    'employment_type',
    'retention_days',
    'effective_from',
    'effective_to',
    'is_active',
    'created_by',
])]
class RecruitmentIncentiveRule extends Model
{
    /** @use HasFactory<RecruitmentIncentiveRuleFactory> */
    use Auditable, BelongsToTenant, HasFactory, ReferencesActiveMasterData;

    /**
     * Phase 8.6 (D8.6-015): what a rule pays, for what and to whom. Once the rule has priced any
     * incentive these are locked — new terms are a new rule (end this one with effective_to).
     *
     * @var array<int, string>
     */
    public const array PRICING_ATTRIBUTES = [
        'trigger_event', 'achievement_metric', 'payout_type', 'fixed_amount', 'slab_upgrade_mode', 'retention_days',
        'employee_id', 'department_id', 'designation_id', 'location_id', 'employment_type', 'effective_from',
    ];

    protected static function booted(): void
    {
        static::saving(function (RecruitmentIncentiveRule $rule): void {
            if ($rule->payout_type === IncentivePayoutType::Fixed && (float) $rule->fixed_amount <= 0) {
                throw new DomainException('A fixed-rate incentive rule needs an amount greater than zero.');
            }

            // Phase 8.6 (D8.6-016): an effective range must run forwards.
            if ($rule->effective_from !== null && $rule->effective_to !== null && $rule->effective_to->lt($rule->effective_from)) {
                throw new DomainException('The effective-to date cannot be before the effective-from date.');
            }

            if ($rule->exists && $rule->isDirty(self::PRICING_ATTRIBUTES) && $rule->isUsed()) {
                throw new DomainException('This rule has already priced incentives, so its terms are locked. End it (set an effective-to date) and create a new rule for the new terms.');
            }
        });

        static::deleting(function (RecruitmentIncentiveRule $rule): void {
            if ($rule->isUsed()) {
                throw new DomainException('This rule has already priced incentives and cannot be deleted — end it with an effective-to date instead.');
            }
        });
    }

    /**
     * Whether any calculation (in any status) was priced by this rule (D8.6-015).
     */
    public function isUsed(): bool
    {
        return $this->exists && RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $this->getKey())->exists();
    }

    protected function casts(): array
    {
        return [
            'trigger_event' => IncentiveTriggerEvent::class,
            'achievement_metric' => TargetMetric::class,
            'payout_type' => IncentivePayoutType::class,
            'fixed_amount' => 'decimal:2',
            'slab_upgrade_mode' => IncentiveSlabUpgradeMode::class,
            'employment_type' => EmploymentType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * @return HasMany<RecruitmentIncentiveSlab, $this>
     */
    public function slabs(): HasMany
    {
        return $this->hasMany(RecruitmentIncentiveSlab::class, 'incentive_rule_id')->orderBy('achievement_min');
    }

    /**
     * Whether this rule applies to the given recruiter, based on its configured scope columns —
     * an unset scope column matches everyone.
     */
    public function appliesTo(Employee $recruiter): bool
    {
        return ($this->employee_id === null || $this->employee_id === $recruiter->id)
            && ($this->department_id === null || $this->department_id === $recruiter->department_id)
            && ($this->designation_id === null || $this->designation_id === $recruiter->designation_id)
            && ($this->location_id === null || $this->location_id === $recruiter->location_id);
    }

    /**
     * A slab-basis value in this rule's unit: "4 joinings" for Slab by count, "80.0%" otherwise.
     */
    public function formatSlabBasis(float $value): string
    {
        return $this->payout_type === IncentivePayoutType::SlabByCount
            ? number_format($value).' '.$this->trigger_event->countNoun()
            : number_format($value, 1).'%';
    }

    /**
     * Phase 8.6 (D8.6-005): master data taken up by this record must be in service.
     *
     * @return array<string, class-string<Model>>
     */
    public function activeMasterDataReferences(): array
    {
        return [
            'department_id' => Department::class,
            'designation_id' => Designation::class,
            'location_id' => Location::class,
        ];
    }
}
