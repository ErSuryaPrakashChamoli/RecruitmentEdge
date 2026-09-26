<?php

namespace App\Services\Intelligence;

use App\Enums\HiringRiskStatus;
use App\Enums\HiringRiskType;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\MetricStatus;
use App\Enums\OfferStatus;
use App\Enums\RecruiterActionType;
use App\Enums\RequisitionStatus;
use App\Enums\RiskSeverity;
use App\Events\HiringRiskDetected;
use App\Models\AuditLog;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Automation\AutomationHealthService;
use App\Services\Intelligence\Data\EvidenceItem;
use App\Services\RecruiterActionService;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hiring Risk Radar™ (Phase 7, detector `risk-radar/1`). Detects risks deterministically — from each
 * open requisition's Hiring Health metrics and from existing services (joining risk, offers near
 * expiry, interviewer feedback backlog, failing automation rules) — and keeps a register with a
 * lifecycle:
 *
 * - one open risk per type + subject (open_key), refreshed on every run (last_seen_at);
 * - risks no longer observed are auto-resolved ("no longer detected");
 * - people acknowledge, dismiss (reason; suppresses re-detection for 7 days) or resolve — audited;
 * - a new high/critical risk creates one Action Center item for its owner and dispatches
 *   HiringRiskDetected (an automation trigger).
 *
 * A risk is a warning with evidence and a recommended action. It never changes a candidate,
 * application or requisition.
 */
class HiringRiskRadar
{
    public const string DETECTOR_VERSION = 'risk-radar/1';

    public const int DISMISS_SUPPRESSION_DAYS = 7;

    public function __construct(
        private readonly HiringHealthService $health,
        private readonly EvidenceRecorder $evidence,
        private readonly RecruiterActionService $actions,
        private readonly AutomationHealthService $automationHealth,
    ) {}

    /**
     * @return array{opened: int, refreshed: int, resolved: int}
     */
    public function scan(?RecruitmentRequisition $only = null, int $limit = 200): array
    {
        $started = now()->subSecond();
        $counts = ['opened' => 0, 'refreshed' => 0, 'resolved' => 0];
        $observe = function (array $risk) use (&$counts): void {
            $counts[$this->observe($risk) ? 'opened' : 'refreshed']++;
        };

        $requisitions = $only !== null
            ? collect([$only])
            : RecruitmentRequisition::query()->where('status', RequisitionStatus::Open)->orderBy('id')->limit($limit)->get();

        foreach ($requisitions as $requisition) {
            foreach ($this->requisitionRisks($requisition) as $risk) {
                $observe($risk);
            }
        }

        $requisitionIds = $requisitions->pluck('id');

        foreach ($this->joiningRisks($requisitionIds, $only !== null) as $risk) {
            $observe($risk);
        }

        foreach ($this->offerRisks($requisitionIds, $only !== null) as $risk) {
            $observe($risk);
        }

        if ($only === null) {
            foreach ([...$this->interviewerRisks(), ...$this->automationRisks()] as $risk) {
                $observe($risk);
            }
        }

        $counts['resolved'] = $this->autoResolve($started, $only);

        return $counts;
    }

    public function acknowledge(HiringRisk $risk, User $actor): HiringRisk
    {
        $this->guardOpen($risk);
        $risk->forceFill(['status' => HiringRiskStatus::Acknowledged, 'acknowledged_by' => $actor->id, 'acknowledged_at' => now()])->save();
        AuditLog::record($risk, 'hiring_risk_acknowledged', null, ['by_user_id' => $actor->id]);

        return $risk;
    }

    public function dismiss(HiringRisk $risk, User $actor, string $reason): HiringRisk
    {
        $this->guardOpen($risk);

        if (blank($reason)) {
            throw new DomainException('A reason is required to dismiss a risk.');
        }

        $risk->forceFill(['status' => HiringRiskStatus::Dismissed, 'resolved_at' => now(), 'resolution' => "Dismissed by {$actor->name}: {$reason}", 'open_key' => null])->save();
        AuditLog::record($risk, 'hiring_risk_dismissed', null, ['reason' => $reason, 'by_user_id' => $actor->id]);

        return $risk;
    }

    public function resolve(HiringRisk $risk, User $actor, ?string $note = null): HiringRisk
    {
        $this->guardOpen($risk);
        $risk->forceFill(['status' => HiringRiskStatus::Resolved, 'resolved_at' => now(), 'resolution' => 'Resolved by '.$actor->name.($note ? ": {$note}" : ''), 'open_key' => null])->save();
        AuditLog::record($risk, 'hiring_risk_resolved', null, ['note' => $note, 'by_user_id' => $actor->id]);

        return $risk;
    }

    /**
     * @param  array{type: HiringRiskType, severity: RiskSeverity, subject: Model, requisition_id: int|null, application_id?: int|null, owner: Employee|null, title: string, description: string, action: string, evidence: array<int, EvidenceItem>}  $risk
     * @return bool Whether a new risk was opened
     */
    private function observe(array $risk): bool
    {
        $key = implode('|', [$risk['type']->value, $risk['subject']->getMorphClass(), $risk['subject']->getKey()]);
        $existing = HiringRisk::query()->where('open_key', $key)->first();

        if ($existing !== null) {
            $escalated = $risk['severity']->rank() < $existing->severity->rank();
            $existing->forceFill(['last_seen_at' => now(), 'severity' => $escalated ? $risk['severity'] : $existing->severity, 'description' => $risk['description']])->save();

            if ($escalated) {
                AuditLog::record($existing, 'hiring_risk_escalated', null, ['severity' => $risk['severity']->value]);
            }

            return false;
        }

        $recentlyDismissed = HiringRisk::query()
            ->where('type', $risk['type'])
            ->where('subject_type', $risk['subject']->getMorphClass())
            ->where('subject_id', $risk['subject']->getKey())
            ->where('status', HiringRiskStatus::Dismissed)
            ->where('resolved_at', '>=', now()->subDays(self::DISMISS_SUPPRESSION_DAYS))
            ->exists();

        if ($recentlyDismissed) {
            return false;
        }

        $owner = $this->actions->reachableOwner($risk['owner']);

        $created = DB::transaction(function () use ($risk, $key, $owner): HiringRisk {
            $created = HiringRisk::query()->create([
                'type' => $risk['type'],
                'severity' => $risk['severity'],
                'status' => HiringRiskStatus::Open,
                'requisition_id' => $risk['requisition_id'],
                'subject_type' => $risk['subject']->getMorphClass(),
                'subject_id' => $risk['subject']->getKey(),
                'candidate_application_id' => $risk['application_id'] ?? null,
                'owner_id' => $owner?->id,
                'title' => $risk['title'],
                'description' => $risk['description'],
                'recommended_action' => $risk['action'],
                'detector_version' => self::DETECTOR_VERSION,
                'open_key' => $key,
                'first_detected_at' => now(),
                'last_seen_at' => now(),
            ]);

            $this->evidence->record($created, $risk['evidence'], 'risk-radar', self::DETECTOR_VERSION);
            AuditLog::record($created, 'hiring_risk_opened', null, ['type' => $risk['type']->value, 'severity' => $risk['severity']->value]);

            return $created;
        });

        if ($owner !== null && in_array($created->severity, [RiskSeverity::High, RiskSeverity::Critical], true)) {
            $action = $this->actions->createOnce("risk:{$created->id}", [
                'title' => $created->title,
                'action_type' => RecruiterActionType::Custom,
                'priority' => $created->severity->actionPriority(),
                'owner_id' => $owner->id,
                'requisition_id' => $created->requisition_id,
                'candidate_application_id' => $created->candidate_application_id,
                'subject_type' => $created->getMorphClass(),
                'subject_id' => $created->id,
                'reason' => "Risk Radar: {$created->description}",
                'suggested_action' => $created->recommended_action,
                'due_at' => now()->addDay(),
            ]);
            $created->forceFill(['recruiter_action_id' => $action->id])->save();
        }

        HiringRiskDetected::dispatch($created);

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requisitionRisks(RecruitmentRequisition $requisition): array
    {
        $snapshot = $this->health->refresh($requisition);
        $breach = fn (string $key) => ($snapshot->metric($key)['status'] ?? null) === MetricStatus::Breach->value;
        $watch = fn (string $key) => ($snapshot->metric($key)['status'] ?? null) === MetricStatus::Watch->value;
        $owner = $requisition->recruiters()->first() ?? $requisition->manager;
        $risks = [];

        $make = function (HiringRiskType $type, RiskSeverity $severity, array $metricKeys, string $title, string $action) use ($requisition, $snapshot, $owner, &$risks): void {
            $metrics = collect($metricKeys)->map(fn (string $key) => $snapshot->metric($key))->filter();
            $risks[] = [
                'type' => $type,
                'severity' => $severity,
                'subject' => $requisition,
                'requisition_id' => $requisition->id,
                'owner' => $owner,
                'title' => "{$title} — {$requisition->code}",
                'description' => $metrics->map(fn (array $m) => "{$m['label']}: {$m['display']} (threshold {$m['threshold']})")->implode('; '),
                'action' => $action,
                'evidence' => $metrics->map(fn (array $m) => EvidenceItem::metric($m['key'], $m['label'], $m['display'], is_numeric($m['value']) ? (float) $m['value'] : null, "{$m['explanation']} Threshold: {$m['threshold']}. Hiring Health snapshot of {$snapshot->computed_at->toDayDateTimeString()}.", $snapshot))->values()->all(),
            ];
        };

        if ($breach('days_open')) {
            $make(HiringRiskType::RequisitionAging, RiskSeverity::High, ['days_open', 'pipeline_depth'], 'Requisition open beyond its ageing limit', 'Review the role with the hiring manager: sourcing plan, requirements or budget.');
        }

        if ($breach('pipeline_depth') || $watch('pipeline_depth')) {
            $make(HiringRiskType::ThinPipeline, $breach('pipeline_depth') ? RiskSeverity::Critical : RiskSeverity::Medium, ['pipeline_depth', 'sourcing_velocity'], $breach('pipeline_depth') ? 'No active candidates' : 'Thin pipeline', 'Source more candidates — try Talent Rediscovery for this role.');
        }

        if ($breach('sourcing_velocity') || $breach('last_qualified_profile')) {
            $make(HiringRiskType::SourcingStalled, RiskSeverity::High, ['sourcing_velocity', 'last_qualified_profile'], 'Sourcing has stalled', 'Add sources, publish the job or run Talent Rediscovery.');
        }

        if ($breach('interview_velocity') || $breach('feedback_pending')) {
            $make(HiringRiskType::SchedulingDelay, RiskSeverity::High, ['interview_velocity', 'feedback_pending'], 'Interview scheduling or outcomes delayed', 'Chase interviewers for slots and outcomes.');
        }

        if ($breach('sla_breaches')) {
            $make(HiringRiskType::RecruiterSla, RiskSeverity::High, ['sla_breaches'], 'Several candidates beyond stage SLA', 'Move or close the candidates waiting beyond SLA.');
        }

        if ($breach('offer_conversion')) {
            $make(HiringRiskType::OfferRisk, RiskSeverity::High, ['offer_conversion'], 'Offers are not converting', 'Review compensation and offer timing against declines.');
        }

        if ($breach('stalled_candidates')) {
            $make(HiringRiskType::StalledCandidates, RiskSeverity::Medium, ['stalled_candidates'], 'Many candidates stalled', 'Follow up with stalled candidates or close them out.');
        }

        if ($breach('drop_off')) {
            $make(HiringRiskType::CandidateDropOff, RiskSeverity::High, ['drop_off'], 'High candidate drop-off', 'Check candidate experience: response times, interview scheduling, communication.');
        }

        if ($watch('source_concentration')) {
            $make(HiringRiskType::SourceDependency, RiskSeverity::Low, ['source_concentration'], 'Pipeline depends on one source', 'Diversify sources to reduce dependency.');
        }

        if ($watch('automation_failures')) {
            $make(HiringRiskType::AutomationFailures, RiskSeverity::Medium, ['automation_failures'], 'Automation failing for this requisition', 'Check the failed runs in Automation Failures.');
        }

        if ($breach('data_completeness')) {
            $make(HiringRiskType::DataQuality, RiskSeverity::Low, ['data_completeness'], 'Requisition data incomplete', 'Fill in the missing requisition fields so intelligence is reliable.');
        }

        return $risks;
    }

    /**
     * @param  Collection<int, int>  $requisitionIds
     * @return array<int, array<string, mixed>>
     */
    private function joiningRisks($requisitionIds, bool $scoped): array
    {
        return CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->when($scoped, fn ($q) => $q->whereHas('candidateApplication', fn ($a) => $a->whereIn('requisition_id', $requisitionIds)))
            ->with(['candidateApplication.candidate', 'candidateApplication.recruiter', 'candidateApplication.requisition'])
            ->limit(500)
            ->get()
            ->filter(fn (CandidateJoining $joining) => $joining->riskLevel() === 'red')
            ->map(fn (CandidateJoining $joining) => [
                'type' => HiringRiskType::JoiningRisk,
                'severity' => RiskSeverity::Critical,
                'subject' => $joining,
                'requisition_id' => $joining->candidateApplication->requisition_id,
                'application_id' => $joining->candidate_application_id,
                'owner' => $joining->candidateApplication->recruiter,
                'title' => "Joining at risk — {$joining->candidateApplication->candidate?->full_name}",
                'description' => "Expected to join on {$joining->expected_doj?->toDateString()} and still {$joining->status->label()}.",
                'action' => 'Call the candidate to reconfirm the joining date.',
                'evidence' => [
                    EvidenceItem::fact('joining', 'Expected joining date', $joining->expected_doj?->toDateString(), $joining),
                    EvidenceItem::fact('joining', 'Joining status', $joining->status->label(), $joining, null, 'Red risk: not confirmed close to the joining date (existing joining risk indicator).'),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $requisitionIds
     * @return array<int, array<string, mixed>>
     */
    private function offerRisks($requisitionIds, bool $scoped): array
    {
        return Offer::query()
            ->where('status', OfferStatus::Released)
            ->whereNotNull('offer_expiry')
            ->where('offer_expiry', '<=', now()->addDays(2)->toDateString())
            ->when($scoped, fn ($q) => $q->whereHas('candidateApplication', fn ($a) => $a->whereIn('requisition_id', $requisitionIds)))
            ->with(['candidateApplication.candidate', 'candidateApplication.recruiter'])
            ->limit(500)
            ->get()
            ->map(fn (Offer $offer) => [
                'type' => HiringRiskType::OfferRisk,
                'severity' => $offer->offer_expiry->isPast() ? RiskSeverity::Critical : RiskSeverity::High,
                'subject' => $offer,
                'requisition_id' => $offer->candidateApplication->requisition_id,
                'application_id' => $offer->candidate_application_id,
                'owner' => $offer->candidateApplication->recruiter,
                'title' => "Offer awaiting decision — {$offer->candidateApplication->candidate?->full_name}",
                'description' => "Offer {$offer->offer_code} is released and ".($offer->offer_expiry->isPast() ? 'past its expiry' : 'expires').' on '.$offer->offer_expiry->toDateString().'.',
                'action' => 'Follow up with the candidate for a decision, or extend the offer.',
                'evidence' => [EvidenceItem::fact('offer', 'Offer expiry', $offer->offer_expiry->toDateString(), $offer)],
            ])
            ->all();
    }

    /**
     * Interviewers with 3+ past interviews still without an outcome.
     *
     * @return array<int, array<string, mixed>>
     */
    private function interviewerRisks(): array
    {
        return Interview::query()
            ->where('scheduled_at', '<', now())
            ->where('scheduled_at', '>=', now()->subDays(30))
            ->whereNotIn('status', [InterviewStatus::Completed, InterviewStatus::Cancelled, InterviewStatus::NoShow, InterviewStatus::Hold])
            ->whereNotNull('interviewer_id')
            ->with('interviewer.reportsTo')
            ->get()
            ->groupBy('interviewer_id')
            ->filter(fn ($interviews) => $interviews->count() >= 3)
            ->map(fn ($interviews) => [
                'type' => HiringRiskType::InterviewerBottleneck,
                'severity' => $interviews->count() >= 6 ? RiskSeverity::Critical : RiskSeverity::High,
                'subject' => $interviews->first()->interviewer,
                'requisition_id' => null,
                'owner' => $interviews->first()->interviewer?->reportsTo ?? $interviews->first()->interviewer,
                'title' => "Interviewer backlog — {$interviews->first()->interviewer?->fullName()}",
                'description' => "{$interviews->count()} past interviews without an outcome in the last 30 days.",
                'action' => 'Ask the interviewer to record outcomes, or reassign upcoming interviews.',
                'evidence' => $interviews->take(10)->map(fn (Interview $i) => EvidenceItem::fact('interviews', 'Interview without outcome', $i->scheduled_at->toDayDateTimeString(), $i))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Active automation rules whose health is Failed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function automationRisks(): array
    {
        return collect($this->automationHealth->overview()['rules'])
            ->filter(fn (array $row) => $row['status'] === AutomationHealthService::FAILED)
            ->map(fn (array $row) => [
                'type' => HiringRiskType::AutomationFailures,
                'severity' => RiskSeverity::High,
                'subject' => $row['rule'],
                'requisition_id' => null,
                'owner' => $row['rule']->owner?->employee,
                'title' => "Automation rule failing — {$row['rule']->name}",
                'description' => collect($row['signals'])->pluck('message')->implode(' '),
                'action' => 'Open the rule\'s failed runs, fix the cause and retry.',
                'evidence' => collect($row['signals'])->map(fn (array $s) => EvidenceItem::metric('automation', 'Automation health signal', $s['message'], null, null, $row['rule']))->all(),
            ])
            ->values()
            ->all();
    }

    private function autoResolve(CarbonInterface $started, ?RecruitmentRequisition $only): int
    {
        $stale = HiringRisk::query()
            ->open()
            ->where('detector_version', self::DETECTOR_VERSION)
            ->where('last_seen_at', '<', $started)
            ->when($only !== null, fn ($q) => $q->where('requisition_id', $only->id))
            ->get();

        foreach ($stale as $risk) {
            $risk->forceFill(['status' => HiringRiskStatus::Resolved, 'resolved_at' => now(), 'resolution' => 'No longer detected by the Risk Radar.', 'open_key' => null])->save();
            AuditLog::record($risk, 'hiring_risk_auto_resolved', null, ['type' => $risk->type->value]);
        }

        return $stale->count();
    }

    private function guardOpen(HiringRisk $risk): void
    {
        if (! $risk->isOpen()) {
            throw new DomainException("This risk is already {$risk->status->label()}.");
        }
    }
}
