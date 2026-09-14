<?php

namespace App\Services;

use App\Enums\IncentiveAdjustmentType;
use App\Enums\IncentiveCalculationStatus;
use App\Filament\Resources\RecruiterIncentiveCalculations\RecruiterIncentiveCalculationResource;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to change an incentive calculation's status, record a payment, or
 * adjust/reverse an amount. Every status change is written atomically with a permanent
 * `recruiter_incentive_approvals` row (Section 27/28) — incentive calculations are financially
 * sensitive and must never be silently overwritten.
 *
 * Paid is reachable only through pay() (which records a RecruiterIncentivePayment) and Reversed
 * only through reverse() (which records the zeroing Reversal adjustment); moveTo() refuses both.
 */
class IncentiveApprovalService
{
    /**
     * @var array<string, array<int, string>>
     */
    private const array ALLOWED_TRANSITIONS = [
        'calculated' => ['pending_verification', 'rejected'],
        'pending_verification' => ['approved', 'rejected'],
        'approved' => ['payable', 'reversed'],
        'payable' => ['paid', 'reversed'],
        'paid' => ['reversed'],
        'rejected' => [],
        'reversed' => [],
    ];

    public function __construct(private readonly NotificationDispatchService $notifications) {}

    /**
     * The plain status transition. Paid and Reversed carry their own financial records and are
     * refused here — use pay() or reverse().
     */
    public function moveTo(
        RecruiterIncentiveCalculation $calculation,
        IncentiveCalculationStatus $to,
        ?Employee $actor = null,
        ?string $remarks = null,
    ): RecruiterIncentiveCalculation {
        if ($to === IncentiveCalculationStatus::Paid) {
            throw new DomainException('An incentive can only be marked Paid by recording a payment.');
        }

        if ($to === IncentiveCalculationStatus::Reversed) {
            throw new DomainException('An incentive can only be reversed through the Reverse action, which records the reversal.');
        }

        return $this->transition($calculation, $to, $actor, $remarks);
    }

    public function submitForVerification(RecruiterIncentiveCalculation $calculation, ?Employee $actor = null, ?string $remarks = null): RecruiterIncentiveCalculation
    {
        return $this->moveTo($calculation, IncentiveCalculationStatus::PendingVerification, $actor, $remarks);
    }

    public function approve(RecruiterIncentiveCalculation $calculation, ?Employee $actor = null, ?string $remarks = null): RecruiterIncentiveCalculation
    {
        return $this->moveTo($calculation, IncentiveCalculationStatus::Approved, $actor, $remarks);
    }

    public function markPayable(RecruiterIncentiveCalculation $calculation, ?Employee $actor = null, ?string $remarks = null): RecruiterIncentiveCalculation
    {
        return $this->moveTo($calculation, IncentiveCalculationStatus::Payable, $actor, $remarks);
    }

    public function reject(RecruiterIncentiveCalculation $calculation, string $remarks, ?Employee $actor = null): RecruiterIncentiveCalculation
    {
        if (blank($remarks)) {
            throw new DomainException('Remarks are required to reject an incentive.');
        }

        return $this->moveTo($calculation, IncentiveCalculationStatus::Rejected, $actor, $remarks);
    }

    /**
     * Writes the first trail row for a freshly created calculation (no previous status). Called by
     * RecruiterIncentiveCalculator inside the same transaction that creates the row.
     */
    public function recordCalculated(RecruiterIncentiveCalculation $calculation, ?Employee $actor = null, string $remarks = 'Incentive calculated'): RecruiterIncentiveCalculation
    {
        $calculation->approvals()->create([
            'from_status' => null,
            'to_status' => $calculation->status,
            'changed_by' => $actor?->id,
            'remarks' => $remarks,
        ]);

        return $calculation;
    }

    /**
     * A recalculation may only move a calculation between Calculated and Pending Verification
     * (e.g. a retention hold that now applies, or no longer does). Unlike moveTo() this permits
     * Pending Verification -> Calculated, because it is a system re-derivation rather than a user
     * decision — but it is still written to the trail. A no-op when the status is unchanged.
     */
    public function applyRecalculatedStatus(
        RecruiterIncentiveCalculation $calculation,
        IncentiveCalculationStatus $to,
        ?Employee $actor = null,
    ): RecruiterIncentiveCalculation {
        $from = $calculation->status;
        $recalculable = [IncentiveCalculationStatus::Calculated, IncentiveCalculationStatus::PendingVerification];

        if (! in_array($from, $recalculable, true) || ! in_array($to, $recalculable, true)) {
            throw new DomainException("A recalculation cannot move an incentive from {$from->label()} to {$to->label()}.");
        }

        if ($from === $to) {
            return $calculation;
        }

        return DB::transaction(function () use ($calculation, $from, $to, $actor): RecruiterIncentiveCalculation {
            $calculation->forceFill(['status' => $to])->save();

            $calculation->approvals()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $actor?->id,
                'remarks' => $to === IncentiveCalculationStatus::Calculated
                    ? 'Recalculated: retention hold applies'
                    : 'Recalculated: retention hold no longer applies',
            ]);

            $this->notifyStatusChange($calculation, $to);

            return $calculation;
        });
    }

    private function transition(
        RecruiterIncentiveCalculation $calculation,
        IncentiveCalculationStatus $to,
        ?Employee $actor,
        ?string $remarks,
    ): RecruiterIncentiveCalculation {
        $from = $calculation->status;

        if (! in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value], true)) {
            throw new DomainException("Cannot move an incentive calculation from {$from->label()} to {$to->label()}.");
        }

        if ($to === IncentiveCalculationStatus::PendingVerification && $calculation->retention_due_at?->isFuture()) {
            throw new DomainException("This incentive is on a retention hold until {$calculation->retention_due_at->format('d M Y')}.");
        }

        return DB::transaction(function () use ($calculation, $from, $to, $actor, $remarks): RecruiterIncentiveCalculation {
            $calculation->forceFill(['status' => $to])->save();

            $calculation->approvals()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $actor?->id,
                'remarks' => $remarks,
            ]);

            $this->notifyStatusChange($calculation, $to);

            return $calculation;
        });
    }

    private function notifyStatusChange(RecruiterIncentiveCalculation $calculation, IncentiveCalculationStatus $to): void
    {
        $url = RecruiterIncentiveCalculationResource::getUrl('view', ['record' => $calculation]);

        match ($to) {
            IncentiveCalculationStatus::PendingVerification => $this->notifications->alert(
                $calculation->employee?->user,
                'Incentives',
                'Incentive pending verification',
                "Your incentive for {$calculation->candidate?->full_name} is ready for verification.",
                'info',
                $url,
            ),
            IncentiveCalculationStatus::Approved => $this->notifications->alert(
                $calculation->employee?->user,
                'Incentives',
                'Incentive approved',
                "Your incentive for {$calculation->candidate?->full_name} has been approved.",
                'success',
                $url,
            ),
            IncentiveCalculationStatus::Rejected => $this->notifications->alert(
                $calculation->employee?->user,
                'Incentives',
                'Incentive rejected',
                "Your incentive for {$calculation->candidate?->full_name} was rejected.",
                'danger',
                $url,
            ),
            IncentiveCalculationStatus::Paid => $this->notifications->alert(
                $calculation->employee?->user,
                'Incentives',
                'Incentive paid',
                "Your incentive for {$calculation->candidate?->full_name} has been paid.",
                'success',
                $url,
            ),
            default => null,
        };
    }

    /**
     * @return array<int, IncentiveCalculationStatus>
     */
    public function allowedNextStatuses(RecruiterIncentiveCalculation $calculation): array
    {
        return array_map(IncentiveCalculationStatus::from(...), self::ALLOWED_TRANSITIONS[$calculation->status->value]);
    }

    /**
     * The only way to reach Paid: records the payment (amount, date, reference) and the Paid trail
     * row in one transaction.
     */
    public function pay(
        RecruiterIncentiveCalculation $calculation,
        float $amount,
        CarbonInterface $paymentDate,
        ?string $reference = null,
        ?Employee $actor = null,
        ?string $remarks = null,
    ): RecruiterIncentiveCalculation {
        if ($calculation->status !== IncentiveCalculationStatus::Payable) {
            throw new DomainException('Only a Payable incentive can be paid.');
        }

        if ($amount <= 0) {
            throw new DomainException('The payment amount must be greater than zero.');
        }

        if (blank($reference)) {
            throw new DomainException('A payment reference is required to record a payment.');
        }

        return DB::transaction(function () use ($calculation, $amount, $paymentDate, $reference, $actor, $remarks): RecruiterIncentiveCalculation {
            $calculation->payments()->create([
                'amount' => $amount,
                'payment_date' => $paymentDate->toDateString(),
                'payment_reference' => $reference,
                'paid_by' => $actor?->id,
                'remarks' => $remarks,
            ]);

            return $this->transition($calculation, IncentiveCalculationStatus::Paid, $actor, $remarks ?? "Payment recorded (ref. {$reference})");
        });
    }

    /**
     * Adds a correction that changes the effective amount without touching the original
     * calculation (Section 28) — usable at any point in the lifecycle.
     */
    public function adjust(RecruiterIncentiveCalculation $calculation, float $amountDelta, string $reason, ?Employee $actor = null): RecruiterIncentiveCalculation
    {
        $calculation->adjustments()->create([
            'adjustment_type' => IncentiveAdjustmentType::Correction,
            'amount_delta' => $amountDelta,
            'reason' => $reason,
            'created_by' => $actor?->id,
        ]);

        return $calculation;
    }

    /**
     * Fully reverses a calculation (e.g. the candidate later became invalid for incentive
     * purposes) by zeroing out its net effective amount via an attributed Reversal adjustment and
     * moving it to Reversed — the original calculation and approval/payment history are never
     * deleted.
     */
    public function reverse(RecruiterIncentiveCalculation $calculation, string $reason, ?Employee $actor = null): RecruiterIncentiveCalculation
    {
        if (blank($reason)) {
            throw new DomainException('Remarks are required to reverse an incentive.');
        }

        if (! in_array(IncentiveCalculationStatus::Reversed->value, self::ALLOWED_TRANSITIONS[$calculation->status->value], true)) {
            throw new DomainException("Cannot reverse an incentive that is {$calculation->status->label()}.");
        }

        return DB::transaction(function () use ($calculation, $reason, $actor): RecruiterIncentiveCalculation {
            $calculation->adjustments()->create([
                'adjustment_type' => IncentiveAdjustmentType::Reversal,
                'amount_delta' => -$calculation->effectiveAmount(),
                'reason' => $reason,
                'created_by' => $actor?->id,
            ]);

            return $this->transition($calculation, IncentiveCalculationStatus::Reversed, $actor, $reason);
        });
    }

    /**
     * Moves every Calculated incentive whose retention period has now elapsed into
     * PendingVerification (Section 26/36 — "retention period completed and incentive now
     * payable"). Intended to run on a schedule; see the `incentives:release-matured` command.
     */
    public function releaseMatured(): int
    {
        $due = RecruiterIncentiveCalculation::query()
            ->where('status', IncentiveCalculationStatus::Calculated)
            ->whereNotNull('retention_due_at')
            ->whereDate('retention_due_at', '<=', now())
            ->get();

        $due->each(fn (RecruiterIncentiveCalculation $calculation) => $this->moveTo(
            $calculation,
            IncentiveCalculationStatus::PendingVerification,
            remarks: 'Retention period completed',
        ));

        return $due->count();
    }
}
