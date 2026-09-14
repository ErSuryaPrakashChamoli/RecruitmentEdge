<?php

namespace App\Services;

use App\Enums\IncentiveCalculationStatus;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruiterIncentivePayment;
use App\Models\User;
use App\Services\Export\ReportExportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The period incentive statement: every calculation one recruiter has for one month, with its
 * rule/application/slab/adjustments and effective amount, totals by status and the payments made
 * against them. Complements the single-calculation statement on the calculation view page.
 */
class IncentiveStatementService
{
    public function __construct(
        private readonly HierarchyService $hierarchy,
        private readonly ReportExportService $exporter,
    ) {}

    /**
     * Anyone with `incentives.view` may download their own statement; another recruiter's needs
     * `reports.export` or `incentives.approve` and that recruiter inside the viewer's hierarchy.
     */
    public function canDownloadFor(User $user, Employee $recruiter): bool
    {
        if ($user->employee_id !== null && $user->employee_id === $recruiter->id) {
            return $user->can('incentives.view');
        }

        return ($user->can('reports.export') || $user->can('incentives.approve'))
            && $this->hierarchy->canView($user, $recruiter);
    }

    /**
     * @return array{recruiter: Employee, periodStart: CarbonImmutable, periodEnd: CarbonImmutable, calculations: Collection<int, RecruiterIncentiveCalculation>, effectiveAmounts: array<int, float>, totalsByStatus: array<string, array{label: string, count: int, amount: float}>, earnedTotal: float, payments: Collection<int, RecruiterIncentivePayment>, paidTotal: float}
     */
    public function periodStatement(Employee $recruiter, CarbonInterface $month): array
    {
        $periodStart = CarbonImmutable::parse($month)->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();

        $calculations = RecruiterIncentiveCalculation::query()
            ->where('employee_id', $recruiter->id)
            ->whereDate('period_start', '>=', $periodStart)
            ->whereDate('period_start', '<=', $periodEnd)
            ->with(['incentiveRule', 'incentiveSlab', 'candidate', 'candidateApplication', 'adjustments', 'payments'])
            ->orderBy('calculated_at')
            ->get();

        $effectiveAmounts = $calculations
            ->mapWithKeys(fn (RecruiterIncentiveCalculation $calculation) => [
                $calculation->id => (float) $calculation->amount + (float) $calculation->adjustments->sum('amount_delta'),
            ])
            ->all();

        $totalsByStatus = [];

        foreach (IncentiveCalculationStatus::cases() as $status) {
            $matching = $calculations->where('status', $status);

            $totalsByStatus[$status->value] = [
                'label' => $status->label(),
                'count' => $matching->count(),
                'amount' => (float) $matching->sum(fn (RecruiterIncentiveCalculation $c) => $effectiveAmounts[$c->id]),
            ];
        }

        $payments = $calculations
            ->flatMap(fn (RecruiterIncentiveCalculation $calculation) => $calculation->payments->each(
                fn (RecruiterIncentivePayment $payment) => $payment->setRelation('calculation', $calculation),
            ))
            ->sortBy('payment_date')
            ->values();

        return [
            'recruiter' => $recruiter,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'calculations' => $calculations,
            'effectiveAmounts' => $effectiveAmounts,
            'totalsByStatus' => $totalsByStatus,
            'earnedTotal' => (float) $calculations
                ->whereNotIn('status', [IncentiveCalculationStatus::Rejected, IncentiveCalculationStatus::Reversed])
                ->sum(fn (RecruiterIncentiveCalculation $c) => $effectiveAmounts[$c->id]),
            'payments' => $payments,
            'paidTotal' => (float) $payments->sum('amount'),
        ];
    }

    public function filename(Employee $recruiter, CarbonInterface $month): string
    {
        $code = $recruiter->employee_code ?: "employee-{$recruiter->id}";

        return 'incentive-statement-'.str($code)->slug().'-'.$month->format('Y-m').'.pdf';
    }

    public function streamPeriodStatement(Employee $recruiter, CarbonInterface $month): StreamedResponse
    {
        return $this->exporter->streamPdf(
            $this->filename($recruiter, $month),
            'pdf.incentive-statement-period',
            $this->periodStatement($recruiter, $month),
        );
    }

    /**
     * Recruiters (employees) the user may pick for a statement, as id => full name.
     *
     * @return array<int, string>
     */
    public function recruiterOptionsFor(User $user): array
    {
        $visibleIds = $this->hierarchy->visibleEmployeeIdsFor($user);

        return Employee::query()
            ->when($visibleIds !== null, fn ($query) => $query->whereIn('id', $visibleIds))
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName()])
            ->all();
    }

    /**
     * The last $count months (current month first) as 'Y-m' => 'F Y' select options.
     *
     * @return array<string, string>
     */
    public function monthOptions(int $count = 12): array
    {
        return collect(range(0, $count - 1))
            ->mapWithKeys(function (int $monthsAgo): array {
                $month = now()->startOfMonth()->subMonthsNoOverflow($monthsAgo);

                return [$month->format('Y-m') => $month->format('F Y')];
            })
            ->all();
    }

    public function parseMonth(string $month): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
    }
}
