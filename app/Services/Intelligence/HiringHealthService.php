<?php

namespace App\Services\Intelligence;

use App\Enums\HealthStatus;
use App\Enums\MetricStatus;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\HiringHealthSnapshot;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\Intelligence\Data\EvidenceItem;
use App\Services\RecruitmentAnalyticsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hiring Health™ (Phase 7, rules `hiring-health/1`): turns the raw per-requisition facts from
 * RecruitmentAnalyticsService::requisitionMetrics() into decomposed metrics — each with its value,
 * threshold, status and evidence — and an overall status derived by named rules:
 *
 * - critical: the pipeline is empty while openings remain, a joining is at red risk, or ≥ 3
 *   metrics breach;
 * - at_risk: any metric breaches;
 * - watch: any metric is on watch;
 * - insufficient_data: under 40% of the requisition's own fields are filled;
 * - healthy otherwise.
 *
 * Thresholds reuse the existing recruitment settings (vacancy ageing, pipeline ratio, stall days,
 * SLA). Snapshots are kept as a time series; a refresh supersedes, never edits.
 */
class HiringHealthService
{
    public const string RULES_VERSION = 'hiring-health/1';

    public const int FRESH_FOR_HOURS = 6;

    public function __construct(
        private readonly RecruitmentAnalyticsService $analytics,
        private readonly EvidenceRecorder $evidence,
    ) {}

    public function currentFor(RecruitmentRequisition $requisition): ?HiringHealthSnapshot
    {
        return HiringHealthSnapshot::query()->where('requisition_id', $requisition->id)->where('is_current', true)->latest('id')->first();
    }

    public function isFresh(?HiringHealthSnapshot $snapshot): bool
    {
        return $snapshot !== null
            && $snapshot->rules_version === self::RULES_VERSION
            && $snapshot->computed_at->gt(now()->subHours(self::FRESH_FOR_HOURS));
    }

    public function refresh(RecruitmentRequisition $requisition, ?User $actor = null, bool $force = false): HiringHealthSnapshot
    {
        $current = $this->currentFor($requisition);

        if (! $force && $this->isFresh($current)) {
            return $current;
        }

        $facts = $this->analytics->requisitionMetrics($requisition);
        [$metrics, $evidence] = $this->evaluate($requisition, $facts);
        $status = $this->overall($metrics, $facts);

        $snapshot = DB::transaction(function () use ($requisition, $metrics, $evidence, $status, $facts): HiringHealthSnapshot {
            // Phase 8.7 (D8.7-026): concurrent refreshes (the hourly command and a manual refresh)
            // take turns on the requisition row, so only one snapshot is ever current.
            RecruitmentRequisition::query()->whereKey($requisition->id)->lockForUpdate()->first();

            HiringHealthSnapshot::query()->where('requisition_id', $requisition->id)->where('is_current', true)->update(['is_current' => false]);

            $snapshot = HiringHealthSnapshot::query()->create([
                'requisition_id' => $requisition->id,
                'status' => $status,
                'rules_version' => self::RULES_VERSION,
                'metrics' => $metrics,
                'breach_count' => collect($metrics)->where('status', MetricStatus::Breach->value)->count(),
                'watch_count' => collect($metrics)->where('status', MetricStatus::Watch->value)->count(),
                'completeness_pct' => $this->completeness($facts),
                'is_current' => true,
                'computed_at' => now(),
            ]);

            $this->evidence->record($snapshot, $evidence, 'hiring-health', self::RULES_VERSION);

            return $snapshot;
        });

        if ($actor !== null) {
            AuditLog::record($snapshot, 'hiring_health_refreshed', null, ['requisition_id' => $requisition->id, 'status' => $status->value, 'by_user_id' => $actor->id]);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, EvidenceItem>}
     */
    private function evaluate(RecruitmentRequisition $requisition, array $facts): array
    {
        $metrics = [];
        $evidence = [];

        $metric = function (string $key, string $label, mixed $value, string $display, string $threshold, MetricStatus $status, string $explanation, array $extraEvidence = []) use (&$metrics, &$evidence): void {
            $metrics[] = ['key' => $key, 'label' => $label, 'value' => $value, 'display' => $display, 'threshold' => $threshold, 'status' => $status->value, 'explanation' => $explanation];
            $evidence[] = EvidenceItem::metric($key, $label, $display, is_numeric($value) ? (float) $value : null, "{$explanation} Threshold: {$threshold}.");
            array_push($evidence, ...$extraEvidence);
        };

        $ageingDays = (int) RecruitmentSetting::get('vacancy_ageing_alert_days', 30);
        $maxDays = (int) RecruitmentSetting::get('position_risk_max_days_open', 45);
        $days = $facts['days_open'];
        $metric('days_open', 'Days open', $days, "{$days} days", "watch at {$ageingDays}, breach at {$maxDays}",
            $days > $maxDays ? MetricStatus::Breach : ($days > $ageingDays ? MetricStatus::Watch : MetricStatus::Ok),
            'Counted from the opening date.',
            [EvidenceItem::fact('days_open', 'Opening date', ($requisition->opening_date ?? $requisition->created_at)->toDateString(), $requisition)]);

        $ratio = (float) RecruitmentSetting::get('position_risk_min_pipeline_ratio', 2.0);
        $remaining = $facts['remaining'];
        $needed = (int) ceil($remaining * $ratio);
        $active = $facts['active_applications'];
        $metric('pipeline_depth', 'Active pipeline', $active, "{$active} active for {$remaining} open position(s)", "at least {$needed} ({$ratio} per open position)",
            match (true) {
                $remaining === 0 => MetricStatus::Ok,
                $active === 0 => MetricStatus::Breach,
                $active < $needed => MetricStatus::Watch,
                default => MetricStatus::Ok,
            },
            $remaining === 0 ? 'All openings are filled.' : 'Active applications compared with what is still to fill.');

        $new = $facts['new_applications_14d'];
        $metric('sourcing_velocity', 'New candidates (14 days)', $new, "{$new} added", 'watch below 2, breach at 0 after 14 days open',
            match (true) {
                $remaining === 0 => MetricStatus::Ok,
                $new === 0 && $days > 14 => MetricStatus::Breach,
                $new < 2 => MetricStatus::Watch,
                default => MetricStatus::Ok,
            },
            'Applications added to this requisition in the last 14 days.');

        $lastShortlist = $facts['last_shortlisted_at'] !== null ? Carbon::parse($facts['last_shortlisted_at']) : null;
        $sinceShortlist = $lastShortlist !== null ? (int) $lastShortlist->diffInDays(now()) : null;
        $metric('last_qualified_profile', 'Last shortlisted candidate', $sinceShortlist, $lastShortlist !== null ? "{$sinceShortlist} days ago ({$lastShortlist->toDateString()})" : 'none yet', 'watch after 14 days, breach after 30',
            match (true) {
                $remaining === 0 => MetricStatus::Ok,
                $sinceShortlist === null => $days > 14 ? MetricStatus::Breach : MetricStatus::Unknown,
                $sinceShortlist > 30 => MetricStatus::Breach,
                $sinceShortlist > 14 => MetricStatus::Watch,
                default => MetricStatus::Ok,
            },
            'When a candidate for this requisition last reached Shortlisted.');

        $lineupTarget = (int) RecruitmentSetting::get('sla_days_shortlist_to_lineup', 2);
        $lineup = $facts['shortlist_to_lineup_days'];
        $metric('interview_velocity', 'Shortlist → interview scheduled', $lineup, $lineup !== null ? "{$lineup} days on average" : 'no data yet', "SLA {$lineupTarget} days (breach at double)",
            match (true) {
                $lineup === null => MetricStatus::Unknown,
                $lineup > $lineupTarget * 2 => MetricStatus::Breach,
                $lineup > $lineupTarget => MetricStatus::Watch,
                default => MetricStatus::Ok,
            },
            'Average time from Shortlisted to Interview Scheduled for this requisition.');

        $pending = $facts['feedback_pending'];
        $metric('feedback_pending', 'Interviews without an outcome', $pending, "{$pending} past interview(s)", 'watch at 1, breach at 3',
            $pending >= 3 ? MetricStatus::Breach : ($pending >= 1 ? MetricStatus::Watch : MetricStatus::Ok),
            'Interviews whose time has passed but that are not completed, cancelled or marked no-show.');

        $breaches = $facts['sla_breaches'];
        $metric('sla_breaches', 'Candidates beyond stage SLA', $breaches->count(), "{$breaches->count()} candidate(s)", 'watch at 1, breach at 3',
            $breaches->count() >= 3 ? MetricStatus::Breach : ($breaches->count() >= 1 ? MetricStatus::Watch : MetricStatus::Ok),
            'Checked with the SLA engine (configured stage SLA or SLA settings).',
            $breaches->take(5)->map(fn (array $row) => EvidenceItem::fact('sla_breaches', "{$row[0]->application_code} beyond SLA", $row[1], $row[0]))->all());

        $decided = $facts['offers_accepted'] + $facts['offers_declined'];
        $declineRate = $decided >= 2 ? round($facts['offers_declined'] / $decided * 100, 1) : null;
        $metric('offer_conversion', 'Offers declined or expired', $declineRate, $decided >= 2 ? "{$facts['offers_declined']} of {$decided} decided offers ({$declineRate}%)" : "{$decided} decided offer(s) — not enough to judge", 'watch at 34%, breach at 50% (needs 2+ decided offers)',
            match (true) {
                $declineRate === null => MetricStatus::Unknown,
                $declineRate >= 50 => MetricStatus::Breach,
                $declineRate >= 34 => MetricStatus::Watch,
                default => MetricStatus::Ok,
            },
            'Share of decided offers that were declined or expired.');

        $red = $facts['joining_red'];
        $metric('joining_risk', 'Joinings at red risk', $red->count(), "{$red->count()} joining(s)", 'breach at 1',
            $red->count() >= 1 ? MetricStatus::Breach : MetricStatus::Ok,
            'Uses the existing joining risk indicator (unconfirmed close to the joining date).',
            $red->take(5)->map(fn (CandidateJoining $j) => EvidenceItem::fact('joining_risk', 'Joining expected '.$j->expected_doj?->toDateString(), $j->status->label(), $j))->all());

        $stalled = $facts['stalled'];
        $stalledShare = $active > 0 ? $stalled->count() / $active : 0;
        $metric('stalled_candidates', 'Stalled candidates', $stalled->count(), "{$stalled->count()} with no activity for {$facts['stall_days']}+ days", 'watch at 1, breach at 30% of the active pipeline (3+)',
            match (true) {
                $stalled->count() >= 3 && $stalledShare >= 0.3 => MetricStatus::Breach,
                $stalled->count() >= 1 => MetricStatus::Watch,
                default => MetricStatus::Ok,
            },
            'Active applications with no recorded activity recently.',
            $stalled->take(5)->map(fn (CandidateApplication $a) => EvidenceItem::fact('stalled_candidates', "{$a->application_code} last active ".($a->last_activity_at ?? $a->created_at)->toDateString(), null, $a))->all());

        $total = $facts['total_applications'];
        $dropped = (int) ($facts['status_counts']['dropout'] ?? 0);
        $dropRate = $total >= 4 ? round($dropped / $total * 100, 1) : null;
        $metric('drop_off', 'Candidate drop-off', $dropRate, $total >= 4 ? "{$dropped} of {$total} dropped out ({$dropRate}%)" : 'fewer than 4 applications', 'watch at 25%, breach at 40% (needs 4+ applications)',
            match (true) {
                $dropRate === null => MetricStatus::Unknown,
                $dropRate >= 40 => MetricStatus::Breach,
                $dropRate >= 25 => MetricStatus::Watch,
                default => MetricStatus::Ok,
            },
            'Applications that ended as candidate dropouts.');

        $sources = collect($facts['source_counts']);
        $topShare = $active >= 5 && $sources->isNotEmpty() ? round($sources->first() / $active * 100, 1) : null;
        $metric('source_concentration', 'Source concentration', $topShare, $topShare !== null ? "{$topShare}% from {$sources->keys()->first()}" : 'fewer than 5 active candidates', 'watch at 80% from one source (needs 5+ active)',
            $topShare !== null && $topShare >= 80 ? MetricStatus::Watch : ($topShare === null ? MetricStatus::Unknown : MetricStatus::Ok),
            'How dependent the active pipeline is on a single source.');

        $failures = $facts['automation_failures_7d'];
        $metric('automation_failures', 'Automation failures (7 days)', $failures, "{$failures} failed run(s)", 'watch at 1',
            $failures >= 1 ? MetricStatus::Watch : MetricStatus::Ok,
            'Failed automation runs about this requisition\'s candidates.');

        $completeness = $this->completeness($facts);
        $missing = collect($facts['completeness'])->reject()->keys();
        $metric('data_completeness', 'Requisition data completeness', $completeness, "{$completeness}%".($missing->isNotEmpty() ? ' — missing: '.$missing->implode(', ') : ''), 'watch below 80%, breach below 50%',
            $completeness < 50 ? MetricStatus::Breach : ($completeness < 80 ? MetricStatus::Watch : MetricStatus::Ok),
            'How much of the requisition is filled in; intelligence is only as good as this.');

        return [$metrics, $evidence];
    }

    /**
     * @param  array<int, array<string, mixed>>  $metrics
     * @param  array<string, mixed>  $facts
     */
    private function overall(array $metrics, array $facts): HealthStatus
    {
        $statuses = collect($metrics)->pluck('status', 'key');
        $breaches = $statuses->filter(fn (string $s) => $s === MetricStatus::Breach->value)->count();

        return match (true) {
            $this->completeness($facts) < 40 => HealthStatus::InsufficientData,
            ($facts['remaining'] > 0 && $facts['active_applications'] === 0) || $statuses['joining_risk'] === MetricStatus::Breach->value || $breaches >= 3 => HealthStatus::Critical,
            $breaches >= 1 => HealthStatus::AtRisk,
            $statuses->contains(MetricStatus::Watch->value) => HealthStatus::Watch,
            default => HealthStatus::Healthy,
        };
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function completeness(array $facts): float
    {
        $fields = collect($facts['completeness']);

        return $fields->isEmpty() ? 0.0 : round($fields->filter()->count() / $fields->count() * 100, 1);
    }
}
