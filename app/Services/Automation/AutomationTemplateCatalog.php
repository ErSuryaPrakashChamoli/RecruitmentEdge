<?php

namespace App\Services\Automation;

use App\Enums\CandidateStage;
use App\Enums\FollowupType;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use Illuminate\Support\Collection;

/**
 * Ready-made automation starting points (Phase 6). A template is only a versioned default
 * configuration: "Use template" creates an ordinary Draft rule the organisation reviews, adjusts,
 * dry-runs and activates. Nothing here is a hard-coded workflow and nothing is active by default.
 *
 * Templates never send a message the Phase 5 listeners/reminders already send (the candidate
 * interview reminder, joining reminder, application acknowledgement and reschedule notice are
 * automatic) — they add the internal side: actions, notifications and escalation.
 *
 * `replaces_alert` names the built-in notifications:dispatch-alerts check this template does the
 * same job as; while a rule made from it is Active that check is skipped, so nobody is alerted
 * twice for the same thing.
 */
class AutomationTemplateCatalog
{
    /**
     * @return Collection<string, array{key: string, version: int, name: string, category: string, description: string, replaces_alert: array<int, string>, rule: array<string, mixed>}>
     */
    public function all(): Collection
    {
        return collect($this->definitions())->keyBy('key');
    }

    /**
     * @return array{key: string, version: int, name: string, category: string, description: string, replaces_alert: array<int, string>, rule: array<string, mixed>}|null
     */
    public function find(string $key): ?array
    {
        return $this->all()->get($key);
    }

    /**
     * @return array<int, array{key: string, version: int, name: string, category: string, description: string, replaces_alert: array<int, string>, rule: array<string, mixed>}>
     */
    private function definitions(): array
    {
        $escalateTo = fn (string $target, int $after, string $priority = 'high', bool $createAction = false, string $unit = 'hours'): array => compact('target', 'after', 'unit', 'priority') + ['create_action' => $createAction];
        $all = fn (array ...$rules): array => ['match' => 'all', 'negate' => false, 'rules' => $rules];
        $any = fn (array ...$rules): array => ['match' => 'any', 'negate' => false, 'rules' => $rules];

        return [
            [
                'key' => 'interview_confirmation_reminder',
                'version' => 1,
                'name' => 'Interview Confirmation Reminder',
                'category' => 'Interviews',
                'description' => 'An interview is within 24 hours and still not confirmed: the recruiter gets a high-priority "Confirm interview" action; if it is still unconfirmed 4 hours later, their manager is alerted. (The candidate\'s own reminder message is already sent automatically.)',
                'replaces_alert' => ['unconfirmed_interviews'],
                'rule' => [
                    'trigger' => 'interview.upcoming',
                    'timing' => ['amount' => 24, 'unit' => 'hours'],
                    'conditions' => $all(['field' => 'interview.confirmed', 'operator' => 'equals', 'value' => '0']),
                    'actions' => [
                        ['type' => 'create_action', 'action_type' => 'confirm_interview', 'owner' => 'recruiter', 'priority' => 'high', 'title' => 'Confirm interview with {{candidate.name}}', 'due_in_hours' => 4, 'suggested_action' => 'Call the candidate and the interviewer, then mark the interview Confirmed.'],
                    ],
                    'escalation' => [
                        'steps' => [$escalateTo('reports_to', 4)],
                        'stop_conditions' => $all(['field' => 'interview.status', 'operator' => 'in', 'value' => [InterviewStatus::Confirmed->value, InterviewStatus::Cancelled->value, InterviewStatus::Completed->value]]),
                    ],
                    'cooldown_minutes' => 1440,
                ],
            ],
            [
                'key' => 'interview_feedback_reminder',
                'version' => 1,
                'name' => 'Interview Feedback Reminder',
                'category' => 'Interviews',
                'description' => 'An interview ended over 24 hours ago with no result recorded: the interviewer gets a "Collect feedback" action; after a further day the recruiter\'s manager is alerted.',
                'replaces_alert' => ['interview_feedback_pending'],
                'rule' => [
                    'trigger' => 'interview.feedback_pending',
                    'timing' => ['amount' => 24, 'unit' => 'hours'],
                    'conditions' => $all(['field' => 'interview.result', 'operator' => 'not_exists']),
                    'actions' => [
                        ['type' => 'create_action', 'action_type' => 'collect_feedback', 'owner' => 'interviewer', 'priority' => 'high', 'title' => 'Record interview feedback for {{candidate.name}}', 'due_in_hours' => 8],
                    ],
                    'escalation' => [
                        'steps' => [$escalateTo('reports_to', 24)],
                        'stop_conditions' => $all(['field' => 'interview.result', 'operator' => 'exists']),
                    ],
                ],
            ],
            [
                'key' => 'offer_pending_escalation',
                'version' => 1,
                'name' => 'Offer Pending Escalation',
                'category' => 'Offers',
                'description' => 'A candidate has been Selected for 24 hours with no offer initiated: the recruiter is notified and given an "Initiate offer" action; the manager is alerted after 4 hours and the VP HR after a day, unless an offer is started first.',
                'replaces_alert' => ['selected_without_offer'],
                'rule' => [
                    'trigger' => 'application.selected_without_offer',
                    'timing' => ['amount' => 24, 'unit' => 'hours'],
                    'conditions' => $all(['field' => 'application.stage', 'operator' => 'equals', 'value' => CandidateStage::Selected->value]),
                    'actions' => [
                        ['type' => 'notify', 'recipient' => 'recruiter', 'priority' => 'high', 'title' => 'Offer pending for {{candidate.name}}', 'message' => '{{candidate.name}} was selected for {{requisition.title}} but no offer has been initiated.'],
                        ['type' => 'create_action', 'action_type' => 'initiate_offer', 'owner' => 'recruiter', 'priority' => 'high', 'title' => 'Initiate offer for {{candidate.name}}', 'due_in_hours' => 8],
                    ],
                    'escalation' => [
                        'steps' => [$escalateTo('reports_to', 4), $escalateTo('role:vp_hr', 24, 'critical')],
                        'stop_conditions' => $any(
                            ['field' => 'offer.status', 'operator' => 'exists'],
                            ['field' => 'application.stage', 'operator' => 'not_equals', 'value' => CandidateStage::Selected->value],
                        ),
                    ],
                ],
            ],
            [
                'key' => 'joining_confirmation_reminder',
                'version' => 1,
                'name' => 'Joining Confirmation Reminder',
                'category' => 'Joining',
                'description' => 'A joining date is 2 days away and not yet confirmed: the recruiter gets a "Confirm joining" action; the manager is alerted a day later if it is still unconfirmed. (The candidate\'s joining reminder is already sent automatically.)',
                'replaces_alert' => ['joining_reminder'],
                'rule' => [
                    'trigger' => 'joining.upcoming',
                    'timing' => ['amount' => 2, 'unit' => 'days'],
                    'conditions' => $all(['field' => 'joining.confirmed', 'operator' => 'equals', 'value' => '0']),
                    'actions' => [
                        ['type' => 'create_action', 'action_type' => 'confirm_joining', 'owner' => 'recruiter', 'priority' => 'high', 'title' => 'Confirm joining with {{candidate.name}}', 'due_in_hours' => 24],
                    ],
                    'escalation' => [
                        'steps' => [$escalateTo('reports_to', 24, 'critical')],
                        'stop_conditions' => $all(['field' => 'joining.status', 'operator' => 'in', 'value' => [JoiningStatus::Confirmed->value, JoiningStatus::Joined->value]]),
                    ],
                ],
            ],
            [
                'key' => 'candidate_no_response_followup',
                'version' => 1,
                'name' => 'Candidate No Response Follow-up',
                'category' => 'Candidates',
                'description' => 'An early-stage candidate has had no activity for 3 business days: a call follow-up is scheduled for the recruiter and a check-in message is sent (if the "candidate_checkin" template is active and the candidate allows it). Runs at most once every 3 days per candidate.',
                'replaces_alert' => [],
                'rule' => [
                    'trigger' => 'application.stuck',
                    'timing' => ['amount' => 3, 'unit' => 'business_days'],
                    'conditions' => $all(['field' => 'application.stage', 'operator' => 'in', 'value' => [CandidateStage::ContactAttempted->value, CandidateStage::Connected->value, CandidateStage::Interested->value]]),
                    'actions' => [
                        ['type' => 'create_followup', 'followup_type' => FollowupType::Call->value, 'due_in_hours' => 4, 'remarks' => 'No response for 3 business days'],
                        ['type' => 'send_communication', 'template_key' => 'candidate_checkin', 'channels' => ['email']],
                    ],
                    'cooldown_minutes' => 4320,
                ],
            ],
            [
                'key' => 'candidate_stage_sla_escalation',
                'version' => 1,
                'name' => 'Candidate Stage SLA Escalation',
                'category' => 'Candidates',
                'description' => 'Checks daily for candidates breaching their stage SLA (configured pipeline stage SLA or the SLA settings): the recruiter is notified and, if it is still breaching the next day, the manager is alerted.',
                'replaces_alert' => ['open_sla_breaches', 'pipeline_stage_sla'],
                'rule' => [
                    'trigger' => 'application.stuck',
                    'timing' => ['amount' => 24, 'unit' => 'hours', 'repeat_every_hours' => 24],
                    'conditions' => $all(['field' => 'application.sla_breached', 'operator' => 'equals', 'value' => '1']),
                    'actions' => [
                        ['type' => 'notify', 'recipient' => 'recruiter', 'priority' => 'high', 'title' => '{{candidate.name}} is breaching the stage SLA', 'message' => '{{candidate.name}} ({{requisition.title}}) has been in the current stage longer than its SLA.'],
                    ],
                    'escalation' => [
                        'steps' => [$escalateTo('reports_to', 24)],
                        'stop_conditions' => $all(['field' => 'application.sla_breached', 'operator' => 'equals', 'value' => '0']),
                    ],
                ],
            ],
            [
                'key' => 'vacancy_ageing_alert',
                'version' => 1,
                'name' => 'Vacancy Ageing Alert',
                'category' => 'Requisitions',
                'description' => 'An open requisition passes 30 days: its manager is notified and given a "Source candidates" action. Repeats weekly while it stays open.',
                'replaces_alert' => ['vacancy_ageing'],
                'rule' => [
                    'trigger' => 'requisition.ageing',
                    'timing' => ['amount' => 30, 'unit' => 'days', 'repeat_every_hours' => 168],
                    'conditions' => $all(),
                    'actions' => [
                        ['type' => 'notify', 'recipient' => 'requisition_manager', 'priority' => 'medium', 'title' => 'Vacancy open for more than 30 days', 'message' => 'A requisition you manage has been open for more than 30 days.'],
                        ['type' => 'create_action', 'action_type' => 'source_candidates', 'owner' => 'requisition_manager', 'priority' => 'medium', 'title' => 'Review sourcing for an ageing vacancy', 'due_in_hours' => 72],
                    ],
                ],
            ],
            [
                'key' => 'referral_followup',
                'version' => 1,
                'name' => 'Referral Follow-up',
                'category' => 'Referrals',
                'description' => 'A referral has waited 2 days for review: the requisition manager gets a "Review" action so the referring employee is not left waiting.',
                'replaces_alert' => [],
                'rule' => [
                    'trigger' => 'referral.awaiting_review',
                    'timing' => ['amount' => 2, 'unit' => 'days'],
                    'conditions' => $all(),
                    'actions' => [
                        ['type' => 'create_action', 'action_type' => 'review_application', 'owner' => 'requisition_manager', 'priority' => 'medium', 'title' => 'Review a pending employee referral', 'due_in_hours' => 24],
                    ],
                ],
            ],
            [
                'key' => 'application_acknowledgement',
                'version' => 1,
                'name' => 'Application Acknowledgement',
                'category' => 'Candidates',
                'description' => 'A candidate applies online: the recruiter gets a "Review application" action due within a day. (The candidate\'s acknowledgement message and the recruiter\'s alert are already sent automatically.)',
                'replaces_alert' => [],
                'rule' => [
                    'trigger' => 'application.applied_online',
                    'timing' => ['mode' => 'immediate'],
                    'conditions' => $all(),
                    'actions' => [
                        ['type' => 'create_action', 'action_type' => 'review_application', 'owner' => 'recruiter', 'priority' => 'medium', 'title' => 'Review online application from {{candidate.name}}', 'due_in_hours' => 24],
                    ],
                ],
            ],
            [
                'key' => 'interview_reschedule_notification',
                'version' => 1,
                'name' => 'Interview Reschedule Notification',
                'category' => 'Interviews',
                'description' => 'An interview is rescheduled: the interviewer is notified of the new time and a note is added to the candidate timeline. (The candidate is already notified automatically.)',
                'replaces_alert' => [],
                'rule' => [
                    'trigger' => 'interview.rescheduled',
                    'timing' => ['mode' => 'immediate'],
                    'conditions' => $all(),
                    'actions' => [
                        ['type' => 'notify', 'recipient' => 'interviewer', 'priority' => 'medium', 'title' => 'Interview rescheduled: {{candidate.name}}', 'message' => 'Now on {{interview.date}} at {{interview.time}} ({{interview.mode}}).'],
                        ['type' => 'add_timeline_event', 'title' => 'Interviewer notified of the new interview time'],
                    ],
                ],
            ],
        ];
    }
}
