<?php

namespace App\Services\Lifecycle;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentRejectionReason;
use App\Services\CandidateJoiningService;
use App\Services\InterviewService;
use App\Services\OfferService;

/**
 * Phase 8.3: when an application becomes Rejected or Dropout, everything still open on it is
 * closed through its own authoritative service, inside the same transaction as the closure:
 *
 * - open interviews → Cancelled (cause recorded; the candidate is not sent a separate
 *   cancellation — what they hear about the closure is communication policy);
 * - open offers (draft, initiated, released) → Withdrawn; an accepted offer stays accepted;
 * - a pending joining → Cancelled on rejection, Dropout (same reason) on dropout.
 *
 * Nothing is deleted, each record keeps why it was closed, and only open items are touched, so
 * running it again changes nothing. None of these services moves the application again, so the
 * cascade cannot recurse. A summary row is audited on the application.
 */
class ApplicationClosureCascade
{
    public function __construct(
        private readonly InterviewService $interviews,
        private readonly OfferService $offers,
        private readonly CandidateJoiningService $joinings,
    ) {}

    /**
     * @return array{interviews_cancelled: array<int, int>, offers_withdrawn: int, joining: ?string}
     */
    public function close(CandidateApplication $application, ApplicationStatus $status, RecruitmentRejectionReason $reason, ?Employee $actor): array
    {
        $cause = $status === ApplicationStatus::Rejected ? 'application_rejected' : 'application_dropout';
        $remarks = $status === ApplicationStatus::Rejected ? 'Cancelled due to application rejection' : 'Cancelled due to application dropout';

        $cancelled = $application->interviews()
            ->whereNotIn('status', [InterviewStatus::Completed->value, InterviewStatus::Cancelled->value, InterviewStatus::NoShow->value])
            ->get()
            ->each(fn (Interview $interview) => $this->interviews->cancel($interview, $remarks, $actor, $cause))
            ->pluck('id')
            ->all();

        $withdrawn = $this->offers->withdrawOpenOffers($application, $actor, $status === ApplicationStatus::Rejected ? 'Withdrawn due to application rejection' : 'Withdrawn due to application dropout');

        $joining = $application->joining()->whereIn('status', [JoiningStatus::Expected->value, JoiningStatus::Confirmed->value])->first();
        $joiningOutcome = null;

        if ($joining !== null) {
            $status === ApplicationStatus::Rejected
                ? $this->joinings->cancel($joining, "Application rejected: {$reason->name}", $actor)
                : $this->joinings->recordApplicationDropout($joining, $reason);
            $joiningOutcome = $joining->status->value;
        }

        $summary = ['interviews_cancelled' => $cancelled, 'offers_withdrawn' => $withdrawn, 'joining' => $joiningOutcome];

        if ($cancelled !== [] || $withdrawn > 0 || $joiningOutcome !== null) {
            AuditLog::record($application, 'lifecycle_cascade', null, ['cause' => $cause, ...$summary, 'by_employee_id' => $actor?->id]);
        }

        return $summary;
    }
}
