<?php

namespace App\Services\Intelligence;

use App\Enums\ApplicationStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\CandidateStage;
use App\Enums\MemoryType;
use App\Enums\OfferStatus;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateStageHistory;
use App\Models\HiringMemoryRecord;
use App\Models\Offer;
use App\Models\RecruiterAction;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Intelligence\Data\EvidenceItem;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hiring Memory™ (Phase 7): captures what actually happened in each hiring event as an immutable,
 * attributable record of deterministic facts, at the moment it happened — a hire, a rejection, an
 * offer not converted, a failed joining, a requisition closing. Capture is idempotent (capture
 * key); a correction creates a new version that supersedes the old one, which is kept. Memory feeds
 * Role DNA historical patterns. Post-joining outcomes (30/60/90/180 days) are Phase 8.
 *
 * Facts deliberately exclude contact details and compensation figures.
 */
class HiringMemoryService
{
    public const string GENERATOR = 'hiring-memory';

    public const string VERSION = '1';

    public function __construct(private readonly EvidenceRecorder $evidence) {}

    public function captureHire(CandidateApplication $application, ?CandidateJoining $joining = null): ?HiringMemoryRecord
    {
        $application->loadMissing(['candidate.source', 'requisition.designation', 'requisition.department', 'requisition.location', 'recruiter', 'interviews']);
        $candidate = $application->candidate;
        // Phase 8.2: the joining record is the source of truth for when a hire happened.
        $joinedAt = $joining?->actual_doj
            ?? $application->stageHistory()->where('new_stage', CandidateStage::Joined)->latest('created_at')->value('created_at')
            ?? now();
        $days = $application->application_date !== null ? (int) $application->application_date->diffInDays($joinedAt) : null;

        $facts = [
            ...$this->requisitionFacts($application->requisition),
            'application_code' => $application->application_code,
            'skills' => array_values($candidate->skills ?? []),
            'total_experience' => $candidate->total_experience !== null ? (float) $candidate->total_experience : null,
            'relevant_experience' => $candidate->relevant_experience !== null ? (float) $candidate->relevant_experience : null,
            'qualification' => $candidate->qualification,
            'source' => $candidate->source?->name,
            'referral' => $candidate->referral_employee_id !== null,
            'origin_channel' => $application->origin_channel,
            'recruiter_id' => $application->recruiter_id,
            'days_to_hire' => $days,
            'interview_rounds' => $application->interviews->count(),
            'stage_days' => $this->stageDurations($application),
        ];

        $summary = "Hired for {$facts['designation']} ({$facts['requisition_code']})".($days !== null ? " in {$days} days" : '').($facts['source'] ? " via {$facts['source']}" : '').", {$facts['interview_rounds']} interview round(s).";

        return $this->capture(MemoryType::Hire, $application, $application->requisition, $application, $facts, $summary, 'candidate.joined', "hire:{$application->id}");
    }

    public function captureRejection(CandidateApplication $application, ?string $remarks = null): ?HiringMemoryRecord
    {
        $application->loadMissing(['requisition.designation', 'requisition.department', 'requisition.location', 'rejectionReason', 'dropoutReason']);
        $isDropout = $application->status === ApplicationStatus::Dropout;
        $reason = $isDropout ? $application->dropoutReason : $application->rejectionReason;

        $facts = [
            ...$this->requisitionFacts($application->requisition),
            'application_code' => $application->application_code,
            'outcome' => $application->status->value,
            'stage' => $application->current_stage->value,
            'stage_label' => $application->current_stage->label(),
            'reason' => $reason?->name,
            'reason_category' => $reason?->category?->value,
            'days_in_process' => $application->application_date !== null ? (int) $application->application_date->diffInDays(now()) : null,
        ];

        $summary = ($isDropout ? 'Candidate dropped out' : 'Not progressed')." at {$facts['stage_label']} for {$facts['designation']}".($facts['reason'] ? " — {$facts['reason']}" : '').'.';

        return $this->capture(MemoryType::Rejection, $application, $application->requisition, $application, $facts, $summary, $isDropout ? 'application.dropout' : 'application.rejected', "rejection:{$application->id}:{$application->status->value}");
    }

    public function captureOfferOutcome(Offer $offer, OfferStatus $to, ?string $remarks = null): ?HiringMemoryRecord
    {
        $offer->loadMissing(['candidateApplication.requisition.designation', 'candidateApplication.requisition.department', 'candidateApplication.requisition.location']);
        $application = $offer->candidateApplication;
        $releasedAt = $offer->statusHistory()->where('to_status', OfferStatus::Released)->value('created_at');

        $facts = [
            ...$this->requisitionFacts($application->requisition),
            'offer_code' => $offer->offer_code,
            'outcome' => $to->value,
            'days_after_release' => $releasedAt !== null ? (int) Carbon::parse($releasedAt)->diffInDays(now()) : null,
            'remarks' => $remarks !== null ? mb_substr($remarks, 0, 300) : null,
        ];

        $summary = "Offer {$to->label()} for {$facts['designation']} ({$facts['requisition_code']})".($facts['days_after_release'] !== null ? ", {$facts['days_after_release']} days after release" : '').'.';

        return $this->capture(MemoryType::OfferOutcome, $offer, $application->requisition, $application, $facts, $summary, 'offer.'.$to->value, "offer:{$offer->id}:{$to->value}");
    }

    public function captureJoiningOutcome(CandidateApplication $application): ?HiringMemoryRecord
    {
        $application->loadMissing(['joining.dropoutReason', 'requisition.designation', 'requisition.department', 'requisition.location']);
        $joining = $application->joining;

        if ($joining === null) {
            return null;
        }

        $facts = [
            ...$this->requisitionFacts($application->requisition),
            'application_code' => $application->application_code,
            'outcome' => $joining->status->value,
            'expected_doj' => $joining->expected_doj?->toDateString(),
            'reason' => $joining->dropoutReason?->name,
        ];

        $summary = "Joining {$joining->status->label()} for {$facts['designation']} (expected {$facts['expected_doj']})".($facts['reason'] ? " — {$facts['reason']}" : '').'.';

        return $this->capture(MemoryType::JoiningOutcome, $joining, $application->requisition, $application, $facts, $summary, 'joining.'.$joining->status->value, "joining:{$joining->id}:{$joining->status->value}");
    }

    public function captureRequisitionOutcome(RecruitmentRequisition $requisition, string $outcome): ?HiringMemoryRecord
    {
        $requisition->loadMissing(['designation', 'department', 'location']);
        $applicationIds = $requisition->applications()->pluck('id');
        $hires = $requisition->applications()->whereIn('current_stage', RecruitmentRequisition::filledStageValues())->with('candidate.source')->get();
        $offers = Offer::query()->whereIn('candidate_application_id', $applicationIds)->pluck('status');

        $facts = [
            ...$this->requisitionFacts($requisition),
            'outcome' => $outcome,
            'days_open' => $requisition->ageingInDays(),
            'openings' => (int) $requisition->openings,
            'hires' => $hires->count(),
            'hires_by_source' => $hires->groupBy(fn (CandidateApplication $a) => $a->candidate?->source?->name ?? 'Unknown')->map->count()->all(),
            'applications' => $applicationIds->count(),
            'offers_released' => $offers->filter(fn (OfferStatus $s) => $s !== OfferStatus::Draft && $s !== OfferStatus::Initiated)->count(),
            'offers_accepted' => $offers->filter(fn (OfferStatus $s) => $s === OfferStatus::Accepted)->count(),
            'slowest_stage' => $this->slowestStage($applicationIds),
            'automation_runs' => AutomationExecution::query()->whereIn('candidate_application_id', $applicationIds)->whereIn('status', [AutomationExecutionStatus::Completed, AutomationExecutionStatus::PartiallyCompleted])->count(),
            'automation_actions' => RecruiterAction::query()->where('requisition_id', $requisition->id)->whereNotNull('automation_rule_id')->count(),
        ];

        $summary = "Requisition {$facts['requisition_code']} {$outcome} after {$facts['days_open']} days: {$facts['hires']} of {$facts['openings']} filled from {$facts['applications']} application(s).";

        return $this->capture(MemoryType::RequisitionOutcome, $requisition, $requisition, null, $facts, $summary, "requisition.{$outcome}", "requisition:{$requisition->id}:{$outcome}");
    }

    /**
     * A person corrects a memory record: a new version with the corrected facts supersedes it. The
     * original is kept (is_current = false) — history is never rewritten.
     *
     * @param  array<string, mixed>  $corrections
     */
    public function correct(HiringMemoryRecord $record, array $corrections, string $reason, User $actor): HiringMemoryRecord
    {
        if (! $record->is_current) {
            throw new DomainException('Only the current version of a memory can be corrected.');
        }

        if (blank($reason)) {
            throw new DomainException('A reason is required to correct Hiring Memory.');
        }

        $unknown = array_diff(array_keys($corrections), array_keys($record->facts));

        if ($unknown !== []) {
            throw new DomainException('Only recorded facts can be corrected: '.implode(', ', $unknown).'.');
        }

        return DB::transaction(function () use ($record, $corrections, $reason, $actor): HiringMemoryRecord {
            $record->forceFill(['is_current' => false])->save();

            $corrected = HiringMemoryRecord::query()->create([
                ...$record->only(['memory_type', 'subject_type', 'subject_id', 'requisition_id', 'designation_id', 'department_id', 'candidate_application_id', 'summary', 'captured_at', 'source_event']),
                'facts' => [...$record->facts, ...$corrections],
                'capture_key' => mb_substr(preg_replace('/:v\d+$/', '', $record->capture_key).':v'.($record->version + 1), 0, 191),
                'version' => $record->version + 1,
                'supersedes_id' => $record->id,
                'is_current' => true,
                'correction_reason' => $reason,
                'recorded_by' => $actor->id,
            ]);

            $this->evidence->record($corrected, collect($corrections)->map(fn ($value, $key) => EvidenceItem::confirmation("fact:{$key}", "Corrected by {$actor->name}", is_scalar($value) ? (string) $value : json_encode($value), $actor))->values()->all(), self::GENERATOR, self::VERSION);
            AuditLog::record($corrected, 'hiring_memory_corrected', ['version' => $record->version], ['version' => $corrected->version, 'fields' => array_keys($corrections), 'reason' => $reason]);

            return $corrected;
        });
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function capture(MemoryType $type, Model $subject, RecruitmentRequisition $requisition, ?CandidateApplication $application, array $facts, string $summary, string $sourceEvent, string $key): ?HiringMemoryRecord
    {
        if (HiringMemoryRecord::query()->where('capture_key', $key)->exists()) {
            return null;
        }

        try {
            $record = HiringMemoryRecord::query()->create([
                'memory_type' => $type,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'requisition_id' => $requisition->id,
                'designation_id' => $requisition->designation_id,
                'department_id' => $requisition->department_id,
                'candidate_application_id' => $application?->id,
                'facts' => $facts,
                'summary' => $summary,
                'captured_at' => now(),
                'source_event' => $sourceEvent,
                'capture_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->evidence->record($record, array_filter([
            EvidenceItem::fact('source', 'Captured from '.class_basename($subject), $sourceEvent, $subject),
            $application !== null ? EvidenceItem::fact('source', 'Application', $application->application_code, $application) : null,
            EvidenceItem::fact('source', 'Requisition', $requisition->code, $requisition),
        ]), self::GENERATOR, self::VERSION);

        AuditLog::record($record, 'hiring_memory_captured', null, ['type' => $type->value, 'source_event' => $sourceEvent]);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function requisitionFacts(RecruitmentRequisition $requisition): array
    {
        return [
            'requisition_code' => $requisition->code,
            'designation' => $requisition->designation?->name,
            'department' => $requisition->department?->name,
            'location' => $requisition->location?->name,
            'required_skills' => array_values($requisition->skills ?? []),
            'experience_range' => [$requisition->experience_min, $requisition->experience_max],
        ];
    }

    /**
     * Days spent in each stage (from the immutable stage history).
     *
     * @return array<string, float>
     */
    public function stageDurations(CandidateApplication $application): array
    {
        $history = $application->stageHistory()->orderBy('created_at')->get(['new_stage', 'created_at']);

        return $history->values()->mapWithKeys(function (CandidateStageHistory $row, int $index) use ($history) {
            $next = $history->get($index + 1);

            return $next !== null ? [$row->new_stage->value => round($row->created_at->diffInHours($next->created_at) / 24, 1)] : [];
        })->all();
    }

    /**
     * The stage where this requisition's candidates spent longest on average.
     *
     * @param  Collection<int, int>  $applicationIds
     * @return array{stage: string, average_days: float}|null
     */
    private function slowestStage(Collection $applicationIds): ?array
    {
        $durations = [];

        CandidateStageHistory::query()
            ->whereIn('candidate_application_id', $applicationIds)
            ->orderBy('candidate_application_id')
            ->orderBy('created_at')
            ->limit(5000)
            ->get(['candidate_application_id', 'new_stage', 'created_at'])
            ->groupBy('candidate_application_id')
            ->each(function (Collection $rows) use (&$durations): void {
                $rows = $rows->values();

                foreach ($rows as $index => $row) {
                    $next = $rows->get($index + 1);

                    if ($next !== null) {
                        $durations[$row->new_stage->value][] = round($row->created_at->diffInHours($next->created_at) / 24, 1);
                    }
                }
            });

        $averages = collect($durations)->map(fn (array $days) => round(array_sum($days) / count($days), 1))->sortDesc();

        return $averages->isEmpty() ? null : ['stage' => (string) $averages->keys()->first(), 'average_days' => (float) $averages->first()];
    }
}
