<?php

namespace App\Console\Commands;

use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\TargetMetric;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentSetting;
use App\Services\NotificationDispatchService;
use App\Services\RecruiterDailyMetricsService;
use App\Services\RecruitmentAnalyticsService;
use App\Services\RecruitmentSlaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Threshold/time-driven proactive alerts (Section 40) that have no single triggering write —
 * vacancy ageing, SLA breaches, interview/joining reminders, offer expiry, and recruiter/team
 * underperformance. Every check reuses an existing service/query rather than recomputing its own
 * business rule, and every send goes through NotificationDispatchService so repeated runs dedupe.
 * Scheduled hourly in routes/console.php.
 */
#[Signature('notifications:dispatch-alerts')]
#[Description('Scan for SLA breaches, ageing vacancies, upcoming interviews/joinings, expiring offers, and underperformance, and raise persistent notifications for them')]
class DispatchRecruitmentAlerts extends Command
{
    public function handle(
        NotificationDispatchService $notifications,
        RecruitmentAnalyticsService $analytics,
        RecruitmentSlaService $sla,
        RecruiterDailyMetricsService $metrics,
    ): int {
        $sent = 0;

        $sent += $this->checkVacancyAgeing($notifications, $analytics);
        $sent += $this->checkOpenSlaBreaches($notifications, $sla);
        $sent += $this->checkSelectedWithoutOffer($notifications);
        $sent += $this->checkInterviewsTomorrow($notifications);
        $sent += $this->checkInterviewFeedbackPending($notifications);
        $sent += $this->checkOffersNearingExpiry($notifications);
        $sent += $this->checkJoiningTomorrow($notifications);
        $sent += $this->checkJoiningRisk($notifications);
        $sent += $this->checkJoinerDidNotJoin($notifications);
        $sent += $this->checkRecruiterBelowTarget($notifications, $metrics);

        $this->info("Dispatched {$sent} recruitment alert(s).");

        return self::SUCCESS;
    }

    private function checkVacancyAgeing(NotificationDispatchService $notifications, RecruitmentAnalyticsService $analytics): int
    {
        $count = 0;

        foreach ($analytics->vacancyAgeing() as $row) {
            if (! $row['is_overdue']) {
                continue;
            }

            $requisition = $row['requisition'];
            $owner = $requisition->manager ?? $requisition->createdBy;

            $notifications->alert(
                $owner?->user,
                'Recruitment',
                'Vacancy ageing exceeded',
                "{$requisition->code} has been open for {$row['ageing_days']} days.",
                'warning',
                RecruitmentRequisitionResource::getUrl('edit', ['record' => $requisition]),
                "vacancy-ageing-{$requisition->id}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    private function checkOpenSlaBreaches(NotificationDispatchService $notifications, RecruitmentSlaService $sla): int
    {
        $count = 0;

        foreach ($sla->openBreaches() as $breach) {
            /** @var CandidateApplication $application */
            $application = $breach['application'];

            $notifications->alert(
                $application->recruiter?->user,
                'Recruitment',
                'Candidate stuck at stage beyond SLA',
                "{$application->candidate->full_name} has been at \"{$breach['leg_label']}\" for {$breach['days_open']} days (target {$breach['target_days']}).",
                'warning',
                CandidateApplicationResource::getUrl('view', ['record' => $application]),
                "sla-breach-{$application->id}-{$breach['leg_label']}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    private function checkSelectedWithoutOffer(NotificationDispatchService $notifications): int
    {
        $thresholdHours = (int) RecruitmentSetting::get('notification_selected_no_offer_hours', 24);
        $count = 0;

        $applications = CandidateApplication::query()
            ->where('current_stage', CandidateStage::Selected)
            ->where('last_activity_at', '<=', now()->subHours($thresholdHours))
            ->whereDoesntHave('offers', fn ($q) => $q->whereNot('status', OfferStatus::Withdrawn))
            ->with('candidate', 'recruiter')
            ->get();

        foreach ($applications as $application) {
            $notifications->alert(
                $application->recruiter?->user,
                'Offers',
                'Candidate selected but offer pending',
                "{$application->candidate->full_name} was selected but has no offer yet.",
                'warning',
                CandidateApplicationResource::getUrl('view', ['record' => $application]),
                "selected-no-offer-{$application->id}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    private function checkInterviewsTomorrow(NotificationDispatchService $notifications): int
    {
        $tomorrow = now()->addDay()->toDateString();
        $count = 0;

        $interviews = Interview::query()
            ->whereIn('status', [InterviewStatus::Scheduled, InterviewStatus::Confirmed])
            ->whereDate('scheduled_at', $tomorrow)
            ->with('candidateApplication.candidate', 'interviewer')
            ->get();

        foreach ($interviews as $interview) {
            $notifications->alert(
                $interview->interviewer?->user,
                'Interviews',
                'Interview tomorrow',
                "Interview with {$interview->candidateApplication->candidate->full_name} at {$interview->scheduled_at->format('h:i A')} tomorrow.",
                'info',
                InterviewResource::getUrl('edit', ['record' => $interview]),
                "interview-tomorrow-{$interview->id}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    private function checkInterviewFeedbackPending(NotificationDispatchService $notifications): int
    {
        $thresholdHours = (int) RecruitmentSetting::get('notification_feedback_pending_hours', 24);
        $count = 0;

        $interviews = Interview::query()
            ->where('status', InterviewStatus::Completed)
            ->whereNull('result')
            ->where('scheduled_at', '<=', now()->subHours($thresholdHours))
            ->with('candidateApplication.candidate', 'interviewer')
            ->get();

        foreach ($interviews as $interview) {
            $notifications->alert(
                $interview->interviewer?->user,
                'Interviews',
                'Interview feedback pending',
                "Feedback is still pending for {$interview->candidateApplication->candidate->full_name}'s interview.",
                'warning',
                InterviewResource::getUrl('edit', ['record' => $interview]),
                "feedback-pending-{$interview->id}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    private function checkOffersNearingExpiry(NotificationDispatchService $notifications): int
    {
        $warningDays = (int) RecruitmentSetting::get('notification_offer_expiry_warning_days', 2);
        $count = 0;

        $offers = Offer::query()
            ->where('status', OfferStatus::Released)
            ->whereNotNull('offer_expiry')
            ->whereBetween('offer_expiry', [now()->toDateString(), now()->addDays($warningDays)->toDateString()])
            ->with('candidateApplication.candidate', 'candidateApplication.recruiter')
            ->get();

        foreach ($offers as $offer) {
            $application = $offer->candidateApplication;

            $notifications->alert(
                $application->recruiter?->user,
                'Offers',
                'Offer nearing expiry',
                "{$application->candidate->full_name}'s offer expires on {$offer->offer_expiry->format('d M Y')}.",
                'warning',
                OfferResource::getUrl('edit', ['record' => $offer]),
                "offer-expiry-{$offer->id}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    private function checkJoiningTomorrow(NotificationDispatchService $notifications): int
    {
        $tomorrow = now()->addDay()->toDateString();
        $count = 0;

        $joinings = CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->whereDate('expected_doj', $tomorrow)
            ->with('candidateApplication.candidate', 'candidateApplication.recruiter')
            ->get();

        foreach ($joinings as $joining) {
            $application = $joining->candidateApplication;

            $notifications->alert(
                $application->recruiter?->user,
                'Joining',
                'Joining tomorrow',
                "{$application->candidate->full_name} is expected to join tomorrow.",
                'info',
                CandidateJoiningResource::getUrl('edit', ['record' => $joining]),
                "joining-tomorrow-{$joining->id}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    private function checkJoiningRisk(NotificationDispatchService $notifications): int
    {
        $count = 0;

        $joinings = CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->with('candidateApplication.candidate', 'candidateApplication.recruiter')
            ->get();

        foreach ($joinings as $joining) {
            if ($joining->riskLevel() !== 'red') {
                continue;
            }

            $application = $joining->candidateApplication;

            $notifications->alert(
                $application->recruiter?->user,
                'Joining',
                'Joining at risk',
                "{$application->candidate->full_name}'s joining (expected {$joining->expected_doj->format('d M Y')}) is at risk — no confirmation yet.",
                'danger',
                CandidateJoiningResource::getUrl('edit', ['record' => $joining]),
                "joining-risk-{$joining->id}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    private function checkJoinerDidNotJoin(NotificationDispatchService $notifications): int
    {
        $count = 0;

        $joinings = CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->where('expected_doj', '<', now()->toDateString())
            ->with('candidateApplication.candidate', 'candidateApplication.recruiter')
            ->get();

        foreach ($joinings as $joining) {
            $application = $joining->candidateApplication;

            $notifications->alert(
                $application->recruiter?->user,
                'Joining',
                'Expected joiner did not join',
                "{$application->candidate->full_name} did not join by the expected date ({$joining->expected_doj->format('d M Y')}).",
                'danger',
                CandidateJoiningResource::getUrl('edit', ['record' => $joining]),
                "joiner-overdue-{$joining->id}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    private function checkRecruiterBelowTarget(NotificationDispatchService $notifications, RecruiterDailyMetricsService $metrics): int
    {
        $shortfallPercent = (int) RecruitmentSetting::get('notification_recruiter_shortfall_percent', 70);
        $criticalPercent = (int) RecruitmentSetting::get('notification_recruiter_critical_shortfall_percent', 50);
        $count = 0;
        $today = Carbon::today();

        $recruiterIds = CandidateApplication::query()->whereNotNull('recruiter_id')->distinct()->pluck('recruiter_id');

        foreach (Employee::query()->whereIn('id', $recruiterIds)->get() as $recruiter) {
            $accountability = $metrics->accountabilityFor($recruiter, $today, $today);
            $scored = $accountability->filter(fn (array $row) => $row['achievement'] !== null && $row['metric'] === TargetMetric::ProfilesSourced);

            if ($scored->isEmpty()) {
                continue;
            }

            $achievement = (float) $scored->first()['achievement'];

            if ($achievement >= $shortfallPercent) {
                continue;
            }

            $isCritical = $achievement < $criticalPercent;

            $notifications->alert(
                $recruiter->user,
                'Performance',
                $isCritical ? 'Recruiter significantly below target' : 'Recruiter below target',
                "{$recruiter->fullName()} is at {$achievement}% of today's sourcing target.",
                $isCritical ? 'danger' : 'warning',
                null,
                "below-target-{$recruiter->id}-".now()->toDateString(),
            );
            $count++;

            if ($isCritical) {
                $notifications->alert(
                    $recruiter->reportsTo?->user,
                    'Performance',
                    'Team member significantly below target',
                    "{$recruiter->fullName()} is at {$achievement}% of today's sourcing target.",
                    'danger',
                    null,
                    "below-target-escalation-{$recruiter->id}-".now()->toDateString(),
                );
            }
        }

        return $count;
    }
}
