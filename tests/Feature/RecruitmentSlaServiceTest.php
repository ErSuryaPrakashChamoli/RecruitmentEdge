<?php

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\StageHistoryEvent;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\RecruitmentStage;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricService;
use App\Services\PipelineTemplateService;
use App\Services\RecruitmentSlaService;
use App\Services\StageTransitionService;

beforeEach(function (): void {
    $this->service = app(RecruitmentSlaService::class);
    $this->transitions = app(StageTransitionService::class);
    $this->start = now()->startOfMonth();
    $this->end = now()->endOfMonth();
});

test('stageTat measures the median days between two stage transitions', function (): void {
    foreach ([4, 5, 6] as $days) {
        slaCompletedLeg(CandidateStage::Selected, CandidateStage::OfferReleased, $days);
    }

    $leg = $this->service->stageTat($this->start, $this->end)->firstWhere('label', 'Selection -> Offer');

    expect($leg['median_days'])->toBe(5.0)
        ->and($leg['average_days'])->toBe(5.0)
        ->and($leg['sample_size'])->toBe(3);
});

test('stageTat reports the share of legs within target, higher is better', function (): void {
    RecruitmentSetting::put('sla_days_selection_to_offer', '2', 'int');

    foreach ([1, 2, 5, 6] as $days) {
        slaCompletedLeg(CandidateStage::Selected, CandidateStage::OfferReleased, $days);
    }

    $leg = $this->service->stageTat($this->start, $this->end)->firstWhere('label', 'Selection -> Offer');

    expect($leg['target_days'])->toBe(2)
        ->and($leg['breaches'])->toBe(2)
        ->and($leg['compliance_percent'])->toBe(50.0);
});

test('stageTat withholds figures below the minimum sample', function (): void {
    slaCompletedLeg(CandidateStage::Selected, CandidateStage::OfferReleased, 5);

    $leg = $this->service->stageTat($this->start, $this->end)->firstWhere('label', 'Selection -> Offer');

    expect($leg['sample_size'])->toBe(1)
        ->and($leg['average_days'])->toBeNull()
        ->and($leg['compliance_percent'])->toBeNull();
});

test('stageTat returns null figures when no application completed that leg in the period', function (): void {
    $leg = $this->service->stageTat($this->start, $this->end)->firstWhere('label', 'Selection -> Offer');

    expect($leg['average_days'])->toBeNull()
        ->and($leg['compliance_percent'])->toBeNull()
        ->and($leg['sample_size'])->toBe(0);
});

test('a status change never starts or completes an SLA leg', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Interview1, 'new_stage' => CandidateStage::Selected, 'event' => StageHistoryEvent::StageEntered, 'created_at' => now()->subDays(9)]);
    // A hold/reactivation writes a same-stage row; it must not restart the Selection -> Offer clock.
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Selected, 'new_stage' => CandidateStage::Selected, 'event' => StageHistoryEvent::Reactivated, 'created_at' => now()->subDays(1)]);
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Selected, 'new_stage' => CandidateStage::OfferReleased, 'event' => StageHistoryEvent::StageEntered, 'created_at' => now()]);

    $result = app(MetricService::class)->get('sla.leg_compliance', MetricQuery::make(MetricPeriod::between($this->start, $this->end), null));
    $leg = collect($result->detail('legs'))->firstWhere('label', 'Selection -> Offer');

    expect($leg['measured'])->toBe(1)
        ->and($leg['breaches'])->toBe(1);
});

test('timeToHireSummary reports on_track when the median time to hire is within target', function (): void {
    RecruitmentSetting::put('sla_days_time_to_hire_target', '30', 'int');
    slaHires([8, 10, 12]);

    $summary = $this->service->timeToHireSummary($this->start, $this->end);

    expect($summary['median_days'])->toBe(10.0)
        ->and($summary['status'])->toBe('on_track');
});

test('timeToHireSummary reports needs_attention when the median time to hire exceeds target', function (): void {
    RecruitmentSetting::put('sla_days_time_to_hire_target', '5', 'int');
    slaHires([8, 10, 12]);

    $summary = $this->service->timeToHireSummary($this->start, $this->end);

    expect($summary['status'])->toBe('needs_attention');
});

test('timeToHireSummary reports too few hires instead of a status below the minimum sample', function (): void {
    slaHires([10]);

    expect($this->service->timeToHireSummary($this->start, $this->end)['status'])->toBe('insufficient_sample');
});

test('timeToHireSummary reports no_data when there are no joins in the period', function (): void {
    $summary = $this->service->timeToHireSummary($this->start, $this->end);

    expect($summary['median_days'])->toBeNull()
        ->and($summary['status'])->toBe('no_data');
});

test('openPipelineStageBreaches flags applications sitting in a configured stage beyond its SLA hours', function (): void {
    $stage = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create(['sla_hours' => 24]);
    $requisition = RecruitmentRequisition::factory()->create();
    $pipelines = app(PipelineTemplateService::class);
    $pipelines->applyToRequisition($requisition, $pipelines->create(['name' => 'SLA flow'], [['recruitment_stage_id' => $stage->id]]));
    $snapshot = $requisition->pipelineStages()->first();

    $late = CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'current_stage' => CandidateStage::Screened]);
    $fresh = CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'current_stage' => CandidateStage::Screened]);

    foreach ([[$late, now()->subHours(30)], [$fresh, now()->subHours(2)]] as [$application, $enteredAt]) {
        $application->forceFill(['pipeline_stage_id' => $snapshot->id])->save();
        $history = $application->stageHistory()->create(['previous_stage' => CandidateStage::Sourced, 'new_stage' => CandidateStage::Screened, 'new_pipeline_stage_id' => $snapshot->id]);
        $history->forceFill(['created_at' => $enteredAt])->save();
    }

    $breaches = app(RecruitmentSlaService::class)->openPipelineStageBreaches();

    expect($breaches)->toHaveCount(1)
        ->and($breaches->first()['application']->id)->toBe($late->id)
        ->and($breaches->first()['target_hours'])->toBe(24)
        ->and($breaches->first()['hours_open'])->toBeGreaterThanOrEqual(29);
});

test('openBreaches flags an application sitting at a leg\'s start stage longer than its target', function (): void {
    RecruitmentSetting::put('sla_days_selection_to_offer', '3', 'int');

    $late = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);
    $late->stageHistory()->forceCreate(['previous_stage' => null, 'new_stage' => CandidateStage::Selected, 'created_at' => now()->subDays(5)]);
    $recent = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);
    $recent->stageHistory()->forceCreate(['previous_stage' => null, 'new_stage' => CandidateStage::Selected, 'created_at' => now()->subDay()]);

    $breaches = $this->service->openBreaches()->where('leg_label', 'Selection -> Offer');

    expect($breaches->pluck('application.id')->all())->toBe([$late->id])
        ->and($breaches->first()['days_open'])->toBe(5)
        ->and($this->service->breachFor($late))->toContain('Selection -> Offer')
        ->and($this->service->breachFor($recent))->toBeNull();
});

/**
 * An application that entered $from $days days ago and $to now.
 */
function slaCompletedLeg(CandidateStage $from, CandidateStage $to, int $days): CandidateApplication
{
    $application = CandidateApplication::factory()->create(['current_stage' => $to]);
    $application->stageHistory()->forceCreate(['previous_stage' => null, 'new_stage' => $from, 'created_at' => now()->subDays($days)]);
    $application->stageHistory()->forceCreate(['previous_stage' => $from, 'new_stage' => $to, 'created_at' => now()]);

    return $application;
}

/**
 * @param  array<int, int>  $daysToHire
 */
function slaHires(array $daysToHire): void
{
    foreach ($daysToHire as $days) {
        CandidateJoining::factory()->create([
            'candidate_application_id' => CandidateApplication::factory()->create(['application_date' => now()->subDays($days)])->id,
            'status' => JoiningStatus::Joined,
            'actual_doj' => now(),
        ]);
    }
}
