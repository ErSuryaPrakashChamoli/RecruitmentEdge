<?php

namespace App\Services\AI\Privacy;

use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRequisition;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Layer 1 of the Phase 8.1 privacy boundary: the only producer of AI-facing representations of
 * recruitment records. Every method is an explicit allowlist — nothing is ever built from
 * toArray() — and people are identified by AiReference codes, never by name or contact details.
 * Individual compensation never leaves the application: only a derived compensation_fit.
 *
 * Every person a projection touches has their name / email / phone registered in
 * AiSensitiveValues, so the sanitizer and egress guard strip them if they slip into free text.
 * Callers must pass records they have already authorized for the acting user.
 */
class AiProjector
{
    public const string FIT_WITHIN = 'within_budget';

    public const string FIT_ABOVE = 'above_budget';

    public const string FIT_BELOW = 'below_budget';

    public const string FIT_UNKNOWN = 'unknown';

    private const int FEEDBACK_EXCERPT_DEFAULT = 1000;

    public function __construct(
        private readonly AiSensitiveValues $sensitiveValues,
        private readonly AiPayloadSanitizer $sanitizer,
    ) {}

    public function candidateRef(?Candidate $candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }

        $this->sensitiveValues->add($candidate->full_name);
        $this->sensitiveValues->add($candidate->email, 'email');
        $this->sensitiveValues->add($candidate->mobile, 'phone');
        $this->sensitiveValues->add($candidate->alternate_mobile, 'phone');

        return AiReference::candidate($candidate);
    }

    public function employeeRef(?Employee $employee): ?string
    {
        if ($employee === null) {
            return null;
        }

        $this->sensitiveValues->add($employee->fullName());
        $this->sensitiveValues->add($employee->email, 'email');
        $this->sensitiveValues->add($employee->mobile, 'phone');

        return AiReference::employee($employee);
    }

    /**
     * A short candidate row for lists and search results.
     *
     * @return array<string, mixed>
     */
    public function candidateListItem(Candidate $candidate): array
    {
        return [
            'id' => $candidate->id,
            'candidate_ref' => $this->candidateRef($candidate),
            'current_designation' => $candidate->current_designation,
            'current_city' => $candidate->current_city,
            'total_experience' => $this->number($candidate->total_experience),
            'skills' => array_values($candidate->skills ?? []),
        ];
    }

    /**
     * The standard AI view of a candidate profile, with the given (already visible) applications.
     *
     * @param  iterable<CandidateApplication>  $applications
     * @return array<string, mixed>
     */
    public function candidateProfile(Candidate $candidate, iterable $applications = []): array
    {
        return [
            'id' => $candidate->id,
            'candidate_ref' => $this->candidateRef($candidate),
            'skills' => array_values($candidate->skills ?? []),
            'total_experience' => $this->number($candidate->total_experience),
            'relevant_experience' => $this->number($candidate->relevant_experience),
            'qualification' => $candidate->qualification,
            'current_designation' => $candidate->current_designation,
            'notice_period_days' => $candidate->notice_period_days,
            'current_city' => $candidate->current_city,
            'source' => $candidate->source?->name,
            'has_email' => filled($candidate->email),
            'has_mobile' => filled($candidate->mobile),
            'applications' => collect($applications)->map(fn (CandidateApplication $application) => $this->application($application, $candidate))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function candidateComparable(Candidate $candidate, ?CandidateApplication $latestApplication = null): array
    {
        return [
            'id' => $candidate->id,
            'candidate_ref' => $this->candidateRef($candidate),
            'total_experience' => $this->number($candidate->total_experience),
            'relevant_experience' => $this->number($candidate->relevant_experience),
            'current_designation' => $candidate->current_designation,
            'skills' => array_values($candidate->skills ?? []),
            'notice_period_days' => $candidate->notice_period_days,
            'current_stage' => $latestApplication?->current_stage?->label(),
            'compensation_fit' => $this->compensationFit($candidate, $latestApplication?->requisition),
        ];
    }

    /**
     * Facts for a candidate summary: profile, per-application stage/interview signal (results,
     * recommendations, scores — never feedback text, remarks, links or pay).
     *
     * @param  iterable<CandidateApplication>  $applications
     * @return array<string, mixed>
     */
    public function candidateSummaryFacts(Candidate $candidate, iterable $applications): array
    {
        $profile = $this->candidateProfile($candidate, []);
        $profile['applications'] = collect($applications)->map(function (CandidateApplication $application) use ($candidate): array {
            return [
                ...$this->application($application, $candidate),
                'interviews' => $application->interviews->sortBy('round_number')->map(fn (Interview $interview) => [
                    'round_number' => $interview->round_number,
                    'round_name' => $interview->round_name,
                    'status' => $this->label($interview->status),
                    'result' => $this->label($interview->result),
                    'feedback' => $interview->feedback->map(fn ($feedback) => [
                        'score' => $feedback->score,
                        'recommendation' => $this->label($feedback->recommendation),
                    ])->values()->all(),
                ])->values()->all(),
                'stage_history' => $application->stageHistory->sortBy('id')->map(fn ($history) => [
                    'stage' => $this->label($history->new_stage),
                    'at' => $this->date($history->created_at),
                ])->values()->all(),
            ];
        })->values()->all();

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function application(CandidateApplication $application, ?Candidate $candidate = null): array
    {
        $candidate ??= $application->candidate;

        return [
            'id' => $application->id,
            'application_ref' => AiReference::application($application),
            'candidate_ref' => $this->candidateRef($candidate),
            'requisition_ref' => AiReference::requisition($application->requisition),
            'role' => $application->requisition?->designation?->name,
            'stage' => $this->label($application->current_stage),
            'status' => $this->label($application->status),
            'applied_on' => $this->date($application->application_date),
            'recruiter_ref' => $this->employeeRef($application->recruiter),
            'compensation_fit' => $candidate !== null ? $this->compensationFit($candidate, $application->requisition) : self::FIT_UNKNOWN,
        ];
    }

    /**
     * Job-relevant requisition detail. The salary band, remarks and every person's contact
     * details stay in the application; people are employee references.
     *
     * @return array<string, mixed>
     */
    public function requisitionDetail(RecruitmentRequisition $requisition): array
    {
        return [
            'id' => $requisition->id,
            'requisition_ref' => AiReference::requisition($requisition),
            'designation' => $requisition->designation?->name,
            'department' => $requisition->department?->name,
            'location' => $requisition->location?->name,
            'status' => $this->label($requisition->status),
            'priority' => $this->label($requisition->priority),
            'employment_type' => $this->label($requisition->employment_type),
            'openings' => $requisition->openings,
            'experience_min' => $this->number($requisition->experience_min),
            'experience_max' => $this->number($requisition->experience_max),
            'qualification' => $requisition->qualification,
            'skills' => array_values($requisition->skills ?? []),
            'shift' => $requisition->shift,
            'opening_date' => $this->date($requisition->opening_date),
            'target_joining_date' => $this->date($requisition->target_joining_date),
            'closing_date' => $this->date($requisition->closing_date),
            'recruiter_refs' => $requisition->recruiters->map(fn (Employee $recruiter) => $this->employeeRef($recruiter))->values()->all(),
            'manager_ref' => $this->employeeRef($requisition->manager),
            'vp_hr_ref' => $this->employeeRef($requisition->vpHr),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function offer(Offer $offer): array
    {
        $this->candidateRef($offer->candidateApplication?->candidate);

        return [
            'offer_id' => $offer->id,
            'offer_ref' => AiReference::offer($offer),
            'application_id' => $offer->candidate_application_id,
            'application_ref' => AiReference::application($offer->candidateApplication),
            'candidate_ref' => AiReference::candidate($offer->candidateApplication?->candidate),
            'designation' => $offer->designation?->name,
            'status' => $this->label($offer->status),
            'offer_date' => $this->date($offer->offer_date),
            'offer_expiry' => $this->date($offer->offer_expiry),
            'expected_joining_date' => $this->date($offer->expected_joining_date),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function interview(Interview $interview): array
    {
        return [
            'interview_id' => $interview->id,
            'interview_ref' => AiReference::interview($interview),
            'application_id' => $interview->candidate_application_id,
            'application_ref' => AiReference::application($interview->candidateApplication),
            'candidate_ref' => $this->candidateRef($interview->candidateApplication?->candidate),
            'round_number' => $interview->round_number,
            'round_name' => $interview->round_name,
            'interviewer_ref' => $this->employeeRef($interview->interviewer),
            'scheduled_at' => $interview->scheduled_at?->toIso8601String(),
            'mode' => $interview->mode?->value,
            'status' => $this->label($interview->status),
            'result' => $this->label($interview->result),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function joining(CandidateJoining $joining): array
    {
        return [
            'joining_id' => $joining->id,
            'joining_ref' => AiReference::joining($joining),
            'application_ref' => AiReference::application($joining->candidateApplication),
            'candidate_ref' => $this->candidateRef($joining->candidateApplication?->candidate),
            'status' => $this->label($joining->status),
            'expected_doj' => $this->date($joining->expected_doj),
            'risk' => $joining->riskLevel(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function followup(RecruitmentFollowup $followup): array
    {
        return [
            'followup_id' => $followup->id,
            'followup_ref' => AiReference::followup($followup),
            'application_id' => $followup->candidate_application_id,
            'application_ref' => AiReference::application($followup->candidateApplication),
            'candidate_ref' => $this->candidateRef($followup->candidateApplication?->candidate),
            'type' => $this->label($followup->followup_type),
            'due_at' => $followup->followup_date?->toIso8601String(),
            'days_overdue' => $followup->followup_date !== null ? (int) $followup->followup_date->diffInDays(now()) : null,
            'recruiter_ref' => $this->employeeRef($followup->recruiter),
        ];
    }

    /**
     * A timeline entry from CandidateTimelineService, reduced to structure: what happened and
     * when. Actor names, remarks, feedback text, message subjects/bodies and justifications stay
     * in the application.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public function timelineEvent(array $entry): array
    {
        $structural = ['stage_change', 'interview', 'interview_feedback', 'offer', 'activity', 'followup'];
        $type = (string) ($entry['type'] ?? 'event');

        return [
            'type' => $type,
            'event' => in_array($type, $structural, true) ? (string) ($entry['title'] ?? '') : Str::headline($type),
            'at' => $entry['at'] instanceof CarbonInterface ? $entry['at']->toIso8601String() : null,
        ];
    }

    /**
     * Interview feedback prepared for the feedback-summarisation prompt only: interviewers are
     * pseudonymised ("Interviewer 1..n"), and the free text is truncated and scrubbed of every
     * registered name and PII pattern. Never returned as a tool result.
     *
     * @param  Collection<int, Interview>  $interviews
     * @return array<int, array<string, mixed>>
     */
    public function feedbackForSummary(Collection $interviews): array
    {
        $pseudonyms = [];
        $excerptLength = (int) config('ai.privacy.feedback_excerpt_chars', self::FEEDBACK_EXCERPT_DEFAULT);

        foreach ($interviews as $interview) {
            foreach ($interview->feedback as $feedback) {
                $this->employeeRef($feedback->interviewer);
            }
        }

        return $interviews->sortBy('round_number')->map(function (Interview $interview) use (&$pseudonyms, $excerptLength): array {
            return [
                'round_number' => $interview->round_number,
                'round_name' => $interview->round_name,
                'status' => $this->label($interview->status),
                'result' => $this->label($interview->result),
                'feedback' => $interview->feedback->map(function ($feedback) use (&$pseudonyms, $excerptLength): array {
                    $key = (string) ($feedback->interviewer_id ?? 'unknown');
                    $pseudonyms[$key] ??= 'Interviewer '.(count($pseudonyms) + 1);

                    return [
                        'interviewer' => $pseudonyms[$key],
                        'score' => $feedback->score,
                        'recommendation' => $this->label($feedback->recommendation),
                        'feedback_excerpt' => $this->sanitizer->sanitizeText(Str::limit((string) $feedback->feedback, $excerptLength))['text'],
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * A hiring risk with a projected title ("{type} — {reference}"): stored titles can name the
     * candidate or interviewer, so they are never forwarded.
     *
     * @return array<string, mixed>
     */
    public function hiringRisk(HiringRisk $risk): array
    {
        $application = $risk->candidateApplication;
        $this->candidateRef($application?->candidate);

        if ($risk->subject instanceof Employee) {
            $this->employeeRef($risk->subject);
        }

        $reference = AiReference::application($application)
            ?? ($risk->subject instanceof Employee ? AiReference::employee($risk->subject) : null)
            ?? AiReference::requisition($risk->requisition);

        return [
            'id' => $risk->id,
            'risk_ref' => AiReference::risk($risk),
            'type' => $risk->type->label(),
            'severity' => $risk->severity->value,
            'title' => trim($risk->type->label().($reference !== null ? ' — '.$reference : '')),
            'requisition_ref' => AiReference::requisition($risk->requisition),
            'application_ref' => AiReference::application($application),
            'description' => $this->sanitizer->sanitizeText((string) $risk->description)['text'],
            'recommended_action' => $risk->recommended_action,
            'evidence' => $risk->evidence->take(5)->map(fn ($evidence) => $this->sanitizer->sanitizeText(trim($evidence->label.': '.$evidence->value))['text'])->values()->all(),
            'first_detected' => $this->date($risk->first_detected_at),
        ];
    }

    /**
     * The only compensation signal the provider may receive: how a candidate's expectation sits
     * against the requisition budget. The figures themselves never leave the application.
     */
    public function compensationFit(Candidate $candidate, ?RecruitmentRequisition $requisition): string
    {
        $expected = $candidate->expected_salary !== null ? (float) $candidate->expected_salary : null;

        if ($expected === null || $requisition === null || ($requisition->salary_min === null && $requisition->salary_max === null)) {
            return self::FIT_UNKNOWN;
        }

        if ($requisition->salary_max !== null && $expected > (float) $requisition->salary_max) {
            return self::FIT_ABOVE;
        }

        if ($requisition->salary_min !== null && $expected < (float) $requisition->salary_min) {
            return self::FIT_BELOW;
        }

        return self::FIT_WITHIN;
    }

    private function label(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }

        return $value !== null ? (string) $value : null;
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : null;
    }

    private function number(mixed $value): ?float
    {
        return $value !== null ? (float) $value : null;
    }
}
