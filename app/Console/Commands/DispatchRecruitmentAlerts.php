<?php

namespace App\Console\Commands;

use App\Enums\CandidateStage;
use App\Enums\FollowupStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\RecruitmentFollowups\RecruitmentFollowupResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentSetting;
use App\Services\NotificationDispatchService;
use App\Services\PerformanceEngine;
use App\Services\RecruitmentAnalyticsService;
use App\Services\RecruitmentSlaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Threshold/time-driven proactive alerts (Section 40) that have no single triggering write —
 * vacancy ageing, SLA breaches, follow-up/interview/joining reminders, offer expiry, joiner
 * escalations, and recruiter/team underperformance. Every check reuses an existing service/query
 * rather than recomputing its own business rule, and every send goes through
 * NotificationDispatchService with a per-day (or per-date) dedupe key so repeated runs never
 * duplicate a notification. Scheduled hourly in routes/console.php.
 */
#[Signature('notifications:dispatch-alerts')]
#[Description('Scan for SLA breaches, ageing vacancies, due follow-ups, upcoming interviews/joinings, expiring offers, and underperformance, and raise persistent notifications for them')]
class DispatchRecruitmentAlerts extends Command
{
    public function handle(
        NotificationDispatchService $notifications,
        RecruitmentAnalyticsService $analytics,
        RecruitmentSlaService $sla,
        PerformanceEngine $performance,
    ): int {
        $sent = 0;

        $sent += $this->checkVacancyAgeing($notifications, $analytics);
        $sent += $this->checkOpenSlaBreaches($notifications, $sla);
        $sent += $this->checkSelectedWithoutOffer($notifications);
        $sent += $this->checkFollowupsDue($notifications);
        $sent += $this->checkInterviewsTomorrow($notifications);
        $sent += $this->checkUnconfirmedInterviews($notifications);
        $sent += $this->checkInterviewFeedbackPending($notifications);
        $sent += $this->checkOffersNearingExpiry($notifications);
        $sent += $this->checkJoiningTomorrow($notifications);
        $sent += $this->checkJoiningReminder($notifications);
        $sent += $this->checkJoiningRisk($notifications);
        $sent += $this->checkJoinerDidNotJoin($notifications);
        $sent += $this->checkRecruiterAndTeamPerformance($notifications, $performance);

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

    /**
     * Pending follow-ups due today, or already overdue from an earlier day, remind their owner
     * (the follow-up's recruiter) once per day.
     */
    private function checkFollowupsDue(NotificationDispatchService $notifications): int
    {
        $count = 0;

        $followups = RecruitmentFollowup::query()
            ->where('status', FollowupStatus::Pending)
            ->where('followup_date', '<=', now()->endOfDay())
            ->with('candidateApplication.candidate', 'recruiter.user')
            ->get();

        foreach ($followups as $followup) {
            $isOverdue = $followup->followup_date->lt(now()->startOfDay());
            $candidateName = $followup->candidateApplication?->candidate?->full_name ?? 'a candidate';

            $notifications->alert(
                $followup->recruiter?->user,
                'Follow-ups',
                $isOverdue ? 'Follow-up overdue' : 'Follow-up due today',
                $isOverdue
                    ? "Your follow-up with {$candidateName} was due on {$followup->followup_date->format('d M Y')} and is still pending."
                    : "Follow up with {$candidateName} today at {$followup->followup_date->format('h:i A')}.",
                $isOverdue ? 'danger' : 'warning',
                RecruitmentFollowupResource::getUrl('index', ['filters' => ['status' => ['value' => FollowupStatus::Pending->value]]]),
                ($isOverdue ? 'followup-overdue-' : 'followup-due-')."{$followup->id}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    /**
     * Reminds both the interviewer and the owning recruiter (one shared dedupe key, so a recruiter
     * who is also the interviewer gets it once). Includes Rescheduled interviews.
     */
    private function checkInterviewsTomorrow(NotificationDispatchService $notifications): int
    {
        $tomorrow = now()->addDay()->toDateString();
        $count = 0;

        $interviews = Interview::query()
            ->whereIn('status', [...InterviewStatus::unconfirmed(), InterviewStatus::Confirmed])
            ->whereDate('scheduled_at', $tomorrow)
            ->with('candidateApplication.candidate', 'candidateApplication.recruiter.user', 'interviewer.user')
            ->get();

        foreach ($interviews as $interview) {
            $recipients = collect([$interview->interviewer?->user, $interview->candidateApplication->recruiter?->user])
                ->filter()
                ->unique('id');

            foreach ($recipients as $recipient) {
                $notifications->alert(
                    $recipient,
                    'Interviews',
                    'Interview tomorrow',
                    "Interview with {$interview->candidateApplication->candidate->full_name} at {$interview->scheduled_at->format('h:i A')} tomorrow.",
                    'info',
                    InterviewResource::getUrl('edit', ['record' => $interview]),
                    "interview-tomorrow-{$interview->id}-".now()->toDateString(),
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Scheduled/Rescheduled interviews within the next 48 hours that nobody has confirmed yet.
     */
    private function checkUnconfirmedInterviews(NotificationDispatchService $notifications): int
    {
        $count = 0;

        $interviews = Interview::query()
            ->whereIn('status', InterviewStatus::unconfirmed())
            ->whereBetween('scheduled_at', [now(), now()->addHours(48)])
            ->with('candidateApplication.candidate', 'candidateApplication.recruiter.user')
            ->get();

        foreach ($interviews as $interview) {
            $notifications->alert(
                $interview->candidateApplication->recruiter?->user,
                'Interviews',
                'Interview not yet confirmed',
                "{$interview->candidateApplication->candidate->full_name}'s interview on {$interview->scheduled_at->format('d M, h:i A')} is not confirmed yet.",
                'warning',
                InterviewResource::getUrl('edit', ['record' => $interview]),
                "interview-unconfirmed-{$interview->id}-".now()->toDateString(),
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

    /**
     * Reminds the recruiter `joining_reminder_days` days before the expected DOJ (keyed on the DOJ
     * itself, so moving the date re-arms the reminder).
     */
    private function checkJoiningReminder(NotificationDispatchService $notifications): int
    {
        $reminderDays = (int) RecruitmentSetting::get('joining_reminder_days', 2);
        $count = 0;

        if ($reminderDays < 1) {
            return 0;
        }

        $joinings = CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->whereDate('expected_doj', now()->addDays($reminderDays)->toDateString())
            ->with('candidateApplication.candidate', 'candidateApplication.recruiter.user')
            ->get();

        foreach ($joinings as $joining) {
            $application = $joining->candidateApplication;

            $notifications->alert(
                $application->recruiter?->user,
                'Joining',
                'Joining reminder',
                "{$application->candidate->full_name} is expected to join in {$reminderDays} days ({$joining->expected_doj->format('d M Y')}).",
                'info',
                CandidateJoiningResource::getUrl('edit', ['record' => $joining]),
                "joining-reminder-{$joining->id}-{$joining->expected_doj->toDateString()}",
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

    /**
     * Notifies the recruiter for every Expected/Confirmed joiner past their DOJ, and escalates a
     * Confirmed one (the candidate said they'd come) to the recruiter's manager.
     */
    private function checkJoinerDidNotJoin(NotificationDispatchService $notifications): int
    {
        $count = 0;

        $joinings = CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->where('expected_doj', '<', now()->toDateString())
            ->with('candidateApplication.candidate', 'candidateApplication.recruiter.reportsTo.user')
            ->get();

        foreach ($joinings as $joining) {
            $application = $joining->candidateApplication;
            $url = CandidateJoiningResource::getUrl('edit', ['record' => $joining]);

            $notifications->alert(
                $application->recruiter?->user,
                'Joining',
                'Expected joiner did not join',
                "{$application->candidate->full_name} did not join by the expected date ({$joining->expected_doj->format('d M Y')}).",
                'danger',
                $url,
                "joiner-overdue-{$joining->id}-".now()->toDateString(),
            );
            $count++;

            if ($joining->status !== JoiningStatus::Confirmed) {
                continue;
            }

            $recruiterName = $application->recruiter?->fullName() ?? 'unassigned';

            $notifications->alert(
                $application->recruiter?->reportsTo?->user,
                'Joining',
                'Confirmed joiner did not join',
                "{$application->candidate->full_name} confirmed joining for {$joining->expected_doj->format('d M Y')} but has not joined (recruiter: {$recruiterName}).",
                'danger',
                $url,
                "joiner-overdue-escalation-{$joining->id}-".now()->toDateString(),
            );
            $count++;
        }

        return $count;
    }

    /**
     * Month-to-date composite performance score (PerformanceEngine::compositeScoreFor(), which
     * prorates targets to the period) per active recruiter: below the shortfall threshold alerts the
     * recruiter, below the critical threshold also escalates to their manager. Then, per manager,
     * the average score of their scored direct reports below the shortfall threshold raises a
     * team-level alert. Recruiters with no resolvable targets (null score) are skipped entirely.
     */
    private function checkRecruiterAndTeamPerformance(NotificationDispatchService $notifications, PerformanceEngine $performance): int
    {
        $shortfallPercent = (int) RecruitmentSetting::get('notification_recruiter_shortfall_percent', 70);
        $criticalPercent = (int) RecruitmentSetting::get('notification_recruiter_critical_shortfall_percent', 50);
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfDay();
        $today = now()->toDateString();
        $count = 0;

        /** @var Collection<int, array{recruiter: Employee, score: float}> $scored */
        $scored = collect();

        foreach ($performance->activeRecruitersQuery()->with('user', 'reportsTo.user')->get() as $recruiter) {
            $score = $performance->compositeScoreFor($recruiter, $start, $end);

            if ($score === null) {
                continue;
            }

            $scored->push(['recruiter' => $recruiter, 'score' => $score]);

            if ($score >= $shortfallPercent) {
                continue;
            }

            $isCritical = $score < $criticalPercent;
            $message = "{$recruiter->fullName()} is at {$score}% month-to-date performance score (threshold {$shortfallPercent}%).";

            $notifications->alert(
                $recruiter->user,
                'Performance',
                $isCritical ? 'Recruiter significantly below target' : 'Recruiter below target',
                $message,
                $isCritical ? 'danger' : 'warning',
                null,
                "below-target-{$recruiter->id}-{$today}",
            );
            $count++;

            if ($isCritical) {
                $notifications->alert(
                    $recruiter->reportsTo?->user,
                    'Performance',
                    'Team member significantly below target',
                    $message,
                    'danger',
                    null,
                    "below-target-escalation-{$recruiter->id}-{$today}",
                );
                $count++;
            }
        }

        $byManager = $scored
            ->filter(fn (array $row) => $row['recruiter']->reports_to_id !== null)
            ->groupBy(fn (array $row) => $row['recruiter']->reports_to_id);

        foreach ($byManager as $rows) {
            $manager = $rows->first()['recruiter']->reportsTo;
            $average = round((float) $rows->avg('score'), 1);

            if ($manager === null || $average >= $shortfallPercent) {
                continue;
            }

            $notifications->alert(
                $manager->user,
                'Performance',
                'Team below target',
                "Your direct reports averaged {$average}% month-to-date performance score across {$rows->count()} recruiter(s) (threshold {$shortfallPercent}%).",
                $average < $criticalPercent ? 'danger' : 'warning',
                null,
                "team-below-target-{$manager->id}-{$today}",
            );
            $count++;
        }

        return $count;
    }
}
