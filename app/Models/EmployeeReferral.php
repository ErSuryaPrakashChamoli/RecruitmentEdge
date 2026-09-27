<?php

namespace App\Models;

use App\Enums\ReferralIncentiveStatus;
use App\Enums\ReferralRelationship;
use App\Enums\ReferralStatus;
use App\Models\Concerns\Auditable;
use App\Services\HierarchyService;
use Database\Factories\EmployeeReferralFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's referral of a candidate (Phase 4). Status, incentive status and linkage are
 * written only by ReferralService; every change is audited via Auditable (there is no dedicated
 * referral history table).
 */
#[Fillable([
    'referral_code',
    'referrer_id',
    'candidate_id',
    'requisition_id',
    'relationship',
    'referred_at',
    'source_id',
    'notes',
    'incentive_eligible',
    'created_by',
])]
class EmployeeReferral extends Model
{
    /** @use HasFactory<EmployeeReferralFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'relationship' => ReferralRelationship::class,
            'status' => ReferralStatus::class,
            'incentive_status' => ReferralIncentiveStatus::class,
            'incentive_eligible' => 'boolean',
            'referred_at' => 'date',
            'joining_date' => 'date',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'referrer_id');
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<CandidateSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CandidateSource::class, 'source_id')->withTrashed();
    }

    /**
     * @return BelongsTo<RecruitmentRejectionReason, $this>
     */
    public function rejectionReason(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRejectionReason::class, 'rejection_reason_id')->withTrashed();
    }

    /**
     * The bonus in the existing incentive engine, once calculated.
     *
     * @return BelongsTo<RecruiterIncentiveCalculation, $this>
     */
    public function incentiveCalculation(): BelongsTo
    {
        return $this->belongsTo(RecruiterIncentiveCalculation::class, 'incentive_calculation_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }

    /**
     * Referrals $user may see: all with hierarchy.view-all; otherwise those made by anyone in
     * their hierarchy, those whose application's recruiter is in it, and — for reviewers
     * (referrals.review) — general referrals and referrals against requisitions they can see.
     *
     * @param  Builder<EmployeeReferral>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        if ($visibleIds === null) {
            return;
        }

        $query->where(function (Builder $q) use ($visibleIds, $user): void {
            $q->whereIn('referrer_id', $visibleIds)
                ->orWhereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds));

            if ($user->can('referrals.review')) {
                $q->orWhereNull('requisition_id')
                    ->orWhereHas('requisition', fn (Builder $r) => $r->where(fn (Builder $inner) => $inner
                        ->whereIn('reporting_manager_id', $visibleIds)
                        ->orWhereIn('hiring_manager_id', $visibleIds)
                        ->orWhereIn('assistant_manager_id', $visibleIds)
                        ->orWhereIn('manager_id', $visibleIds)
                        ->orWhereIn('vp_hr_id', $visibleIds)
                        ->orWhereIn('created_by', $visibleIds)
                        ->orWhereHas('recruiters', fn (Builder $rec) => $rec->whereIn('employees.id', $visibleIds))));
            }
        });
    }
}
