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

    public function moveTo(
        RecruiterIncentiveCalculation $calculation,
        IncentiveCalculationStatus $to,
        ?Employee $actor = null,
        ?string $remarks = null,
    ): RecruiterIncentiveCalculation {
        $from = $calculation->status;

        if (! in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value], true)) {
            throw new DomainException("Cannot move an incentive calculation from {$from->label()} to {$to->label()}.");
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

        return DB::transaction(function () use ($calculation, $amount, $paymentDate, $reference, $actor, $remarks): RecruiterIncentiveCalculation {
            $calculation->payments()->create([
                'amount' => $amount,
                'payment_date' => $paymentDate->toDateString(),
                'payment_reference' => $reference,
                'paid_by' => $actor?->id,
                'remarks' => $remarks,
            ]);

            return $this->moveTo($calculation, IncentiveCalculationStatus::Paid, $actor, $remarks);
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
     * purposes) by zeroing out its net effective amount via an adjustment and moving it to
     * Reversed — the original calculation and approval/payment history are never deleted.
     */
    public function reverse(RecruiterIncentiveCalculation $calculation, string $reason, ?Employee $actor = null): RecruiterIncentiveCalculation
    {
        return DB::transaction(function () use ($calculation, $reason, $actor): RecruiterIncentiveCalculation {
            $calculation->adjustments()->create([
                'adjustment_type' => IncentiveAdjustmentType::Reversal,
                'amount_delta' => -$calculation->effectiveAmount(),
                'reason' => $reason,
                'created_by' => $actor?->id,
            ]);

            return $this->moveTo($calculation, IncentiveCalculationStatus::Reversed, $actor, $reason);
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
