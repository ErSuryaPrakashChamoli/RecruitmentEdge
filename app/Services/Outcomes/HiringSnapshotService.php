<?php

namespace App\Services\Outcomes;

use App\Enums\JoiningStatus;
use App\Enums\OutcomeCaptureMode;
use App\Models\CandidateJoining;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Interview;
use App\Models\RecruitmentSetting;
use App\Models\RoleDnaProfile;
use App\Services\Intelligence\HiringMemoryService;
use App\Services\Intelligence\IntelligenceText;
use App\Services\RecruitmentAnalyticsService;
use Illuminate\Support\Facades\DB;

/**
 * Outcome Loop™ (Phase 8.2): captures the hiring journey as it was known when a joining record was
 * marked Joined — the completed-hire anchor (the Joined pipeline stage alone never creates one).
 *
 * Stored: references, job-relevant categories (experience band, location fit, skills matched,
 * qualification, source), stage durations, time to hire (the configured start point), interview and
 * offer summaries, the Role DNA / pipeline versions in force. Never stored: names, contact details,
 * pay, remarks or feedback text. The snapshot is immutable; one per joining.
 */
class HiringSnapshotService
{
    public const array EXPERIENCE_BANDS = [[0, 1, '0-1_years'], [1, 3, '1-3_years'], [3, 5, '3-5_years'], [5, 8, '5-8_years'], [8, null, '8+_years']];

    public function __construct(private readonly HiringMemoryService $memory) {}

    public function captureForJoining(CandidateJoining $joining, OutcomeCaptureMode $mode = OutcomeCaptureMode::ObservedGoingForward): ?HiringOutcomeSnapshot
    {
        if ($joining->status !== JoiningStatus::Joined) {
            return null;
        }

        if ($existing = HiringOutcomeSnapshot::query()->where('candidate_joining_id', $joining->id)->first()) {
            return $existing;
        }

        $joining->loadMissing(['offer.statusHistory', 'candidateApplication.candidate.source', 'candidateApplication.requisition.designation', 'candidateApplication.requisition.location', 'candidateApplication.interviews.feedback']);
        $application = $joining->candidateApplication;
        $candidate = $application->candidate;
        $requisition = $application->requisition;
        $joinedOn = $joining->actual_doj ?? $joining->expected_doj;
        $startPoint = (string) RecruitmentSetting::get('time_to_hire_start_point', 'candidate_applied');
        $start = RecruitmentAnalyticsService::timeToHireStart($application, $startPoint);
        $timeToHire = $start !== null ? (int) $start->copy()->startOfDay()->diffInDays($joinedOn->copy()->startOfDay()) : null;

        // Phase 8.3: a start date after the joining date is bad source data — time to hire is
        // unknown, never negative (and never a failed capture).
        if ($timeToHire !== null && $timeToHire < 0) {
            [$start, $timeToHire] = [null, null];
        }
        $required = collect($requisition?->skills ?? [])->map(fn ($skill) => IntelligenceText::skillKey((string) $skill));
        $skills = collect($candidate->skills ?? [])->map(fn ($skill) => IntelligenceText::skillKey((string) $skill))->unique()->values();

        $facts = [
            'experience_band' => $this->experienceBand($candidate->total_experience),
            'qualification' => $candidate->qualification,
            'skills' => $skills->all(),
            'required_skills' => $required->values()->all(),
            'required_skills_matched' => $required->intersect($skills)->count(),
            'location_fit' => $this->locationFit($candidate->current_city, $requisition?->location?->name),
            'source' => $candidate->source?->name,
            'referral' => $candidate->referral_employee_id !== null,
            'origin_channel' => $application->origin_channel,
            'stage_days' => $this->memory->stageDurations($application),
            'time_to_hire' => $start !== null ? ['start_point' => $startPoint, 'start_date' => $start->toDateString(), 'end_date' => $joinedOn->toDateString(), 'days' => $timeToHire] : null,
            'interviews' => $this->interviewSummary($application->interviews),
            'offer' => $joining->offer !== null ? [
                'offer_ref' => $joining->offer->offer_code,
                'statuses' => $joining->offer->statusHistory->sortBy('id')->map(fn ($history) => $history->to_status->value)->unique()->values()->all(),
            ] : null,
            'employment_type' => $requisition?->employment_type?->value,
        ];

        return DB::transaction(fn () => HiringOutcomeSnapshot::query()->firstOrCreate(['candidate_joining_id' => $joining->id], [
            'candidate_application_id' => $application->id,
            'candidate_id' => $candidate->id,
            'requisition_id' => $requisition?->id,
            'employee_id' => $candidate->employee?->id,
            'designation_id' => $requisition?->designation_id,
            'department_id' => $requisition?->department_id,
            'location_id' => $requisition?->location_id,
            'source_id' => $candidate->source_id,
            // The Role DNA in force when the snapshot is taken. A backfill cannot know the version that
            // applied at the time, so it records none rather than a later one.
            'role_dna_version_id' => $mode === OutcomeCaptureMode::BackfilledDeterministic || $requisition === null ? null : RoleDnaProfile::query()->where('requisition_id', $requisition->id)->first()?->currentVersion?->id,
            'pipeline_template_version' => $requisition?->pipeline_template_version,
            'joined_on' => $joinedOn->toDateString(),
            'time_to_hire_days' => $timeToHire,
            'facts' => $facts,
            'capture_mode' => $mode,
            'rules_version' => HiringOutcomeSnapshot::RULES_VERSION,
            'captured_at' => now(),
        ]));
    }

    public function experienceBand(mixed $years): string
    {
        if ($years === null || $years === '') {
            return 'unknown';
        }

        foreach (self::EXPERIENCE_BANDS as [$min, $max, $label]) {
            if ((float) $years >= $min && ($max === null || (float) $years < $max)) {
                return $label;
            }
        }

        return 'unknown';
    }

    private function locationFit(?string $city, ?string $location): string
    {
        if (blank($city) || blank($location)) {
            return 'unknown';
        }

        return IntelligenceText::normalize($city) === IntelligenceText::normalize($location) ? 'same_city' : 'different_city';
    }

    /**
     * Results and recommendations only — never feedback text or interviewer identity.
     *
     * @param  iterable<Interview>  $interviews
     * @return array<string, mixed>
     */
    private function interviewSummary(iterable $interviews): array
    {
        $interviews = collect($interviews);
        $feedback = $interviews->flatMap(fn (Interview $interview) => $interview->feedback);

        return [
            'rounds' => $interviews->count(),
            'results' => $interviews->map(fn (Interview $interview) => $interview->result?->value)->filter()->countBy()->all(),
            'recommendations' => $feedback->map(fn ($f) => $f->recommendation?->value)->filter()->countBy()->all(),
            'average_score' => $feedback->whereNotNull('score')->isNotEmpty() ? round((float) $feedback->whereNotNull('score')->avg('score'), 1) : null,
            'feedback_count' => $feedback->count(),
        ];
    }
}
