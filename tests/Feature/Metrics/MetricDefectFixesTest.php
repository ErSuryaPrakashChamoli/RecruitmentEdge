<?php

use App\Enums\ActivityType;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\HiringRiskType;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Filament\Pages\Pipeline;
use App\Filament\Widgets\JoiningControlCenterWidget;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentDailyTarget;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\AI\Tools\CandidateTools\FindStuckCandidatesTool;
use App\Services\AI\Tools\IntelligenceTools\ListHiringRisksTool;
use App\Services\AI\Tools\RecruiterTools\FindInactiveRecruitersTool;
use App\Services\AI\Tools\RecruitmentTools\ForecastHiringTool;
use App\Services\RecruiterDailyMetricsService;
use App\Services\RecruitmentActionCenterService;
use App\Services\RecruitmentAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.5: the confirmed metric defects DF-1 … DF-14 (DF-4, DF-5, DF-9 and DF-11 have their own
 * tests), each fixed at its source.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $this->recruiter = Employee::factory()->reportingTo($this->manager)->create();
    $this->viewer = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
});

test('DF-1: accountability sets a range actual against the target for the same range', function (): void {
    RecruitmentDailyTarget::factory()->create(['employee_id' => $this->recruiter->id, 'metric' => TargetMetric::Calls, 'period_type' => TargetPeriodType::Daily, 'target_value' => 10, 'effective_from' => now()->subMonth()]);
    RecruitmentDailyActivity::factory()->count(30)->create(['recruiter_id' => $this->recruiter->id, 'activity_type' => ActivityType::Call, 'activity_datetime' => now()->subDays(2)]);

    $calls = app(RecruiterDailyMetricsService::class)->accountabilityFor($this->recruiter, now()->subDays(4)->startOfDay(), now()->endOfDay())
        ->firstWhere('metric', TargetMetric::Calls);

    // Five days at 10 a day: 30 of 50 is 60%, never "30 of 10 = 300%".
    expect($calls['target'])->toBe(50)
        ->and($calls['actual'])->toBe(30)
        ->and($calls['achievement'])->toBe(60.0);
});

test('DF-2: inactive recruiters are recruiters, not every active employee in scope', function (): void {
    CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
    $nonRecruiter = Employee::factory()->reportingTo($this->manager)->create();

    $ids = collect(app(FindInactiveRecruitersTool::class)->handle([], $this->viewer)->data['inactive_recruiters'])->pluck('employee_id');

    expect($ids->all())->toContain($this->recruiter->id)
        ->and($ids->all())->not->toContain($nonRecruiter->id);
});

test('DF-3: hiring risks with no requisition reach the people they concern', function (): void {
    $interviewer = Employee::factory()->reportingTo($this->manager)->create();
    $risk = HiringRisk::query()->create([
        'type' => HiringRiskType::InterviewerBottleneck, 'severity' => 'high', 'status' => 'open', 'requisition_id' => null,
        'subject_type' => $interviewer->getMorphClass(), 'subject_id' => $interviewer->id, 'owner_id' => $this->manager->id,
        'title' => 'Interviewer backlog', 'description' => 'Backlog', 'detector_version' => 'risk-radar/1', 'first_detected_at' => now(), 'last_seen_at' => now(),
    ]);
    $outsiderRisk = HiringRisk::query()->create([
        'type' => HiringRiskType::InterviewerBottleneck, 'severity' => 'high', 'status' => 'open', 'requisition_id' => null,
        'subject_type' => $interviewer->getMorphClass(), 'subject_id' => Employee::factory()->create()->id, 'owner_id' => Employee::factory()->create()->id,
        'title' => 'Interviewer backlog', 'description' => 'Backlog', 'detector_version' => 'risk-radar/1', 'first_detected_at' => now(), 'last_seen_at' => now(),
    ]);

    $data = app(ListHiringRisksTool::class)->handle([], $this->viewer)->data;

    expect($data['total_open'])->toBe(1)
        ->and(collect($data['risks'])->pluck('risk_ref')->implode(','))->toContain((string) $risk->id)
        ->and(collect($data['risks'])->pluck('risk_ref')->implode(','))->not->toContain('RISK-'.$outsiderRisk->id);
});

test('DF-6: stuck candidates use the configured stall threshold, include never-touched applications and skip hires', function (): void {
    RecruitmentSetting::put('candidate_stall_days', '10', 'int');
    $stale = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'last_activity_at' => now()->subDays(12)]);
    CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'last_activity_at' => now()->subDays(8)]);
    $untouched = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'last_activity_at' => null, 'created_at' => now()->subDays(20)]);
    lifecycleFixture(fn () => CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::Joined, 'last_activity_at' => now()->subDays(40)]));

    $ids = collect(app(FindStuckCandidatesTool::class)->handle([], $this->viewer)->data['stuck_applications'])->pluck('application_id');

    expect($ids->sort()->values()->all())->toBe(collect([$stale->id, $untouched->id])->sort()->values()->all());
});

test('DF-7: a pipeline card\'s days in stage are positive, so the staleness dots can show', function (): void {
    actingAs(User::factory()->create()->assignRole('chro'));
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Screened]);
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Sourced, 'new_stage' => CandidateStage::Screened, 'created_at' => now()->subDays(6)]);

    $page = Livewire::test(Pipeline::class)->instance();
    $column = collect($page->getColumns())->first(fn (array $column) => in_array(CandidateStage::Screened, $column['stages'], true));
    $card = $page->getCardsFor($column)['applications']->firstWhere('id', $application->id);

    expect($card->stage_age_days)->toBe(6);
});

test('DF-8: a cancelled joining is closed, never shown as overdue or at risk', function (): void {
    $cancelled = lifecycleFixture(fn () => CandidateJoining::factory()->create(['status' => JoiningStatus::Cancelled, 'expected_doj' => now()->subDays(3)]));

    expect($cancelled->riskLevel())->toBe('closed')
        ->and(app(RecruitmentAnalyticsService::class)->joiningRisks()->pluck('joining.id')->all())->not->toContain($cancelled->id);
});

test('DF-10: the joining control center shows one "Joined" figure', function (): void {
    actingAs(User::factory()->create()->assignRole('chro'));
    $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(10, 0));
    lifecycleFixture(fn () => CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'expected_doj' => now()->subMonth(), 'actual_doj' => now()->toDateString()]));

    $widget = Livewire::test(JoiningControlCenterWidget::class)->instance();
    $pipelineJoined = collect($widget->getPipeline())->firstWhere('label', 'Joined')['count'];

    expect($widget->getSummary()['joined'])->toBe(1)
        ->and($pipelineJoined)->toBe(1);
});

test('DF-12: Hiring Health facts cover the whole requisition, not the first 500 or 200 applications', function (): void {
    $requisition = RecruitmentRequisition::factory()->create();
    CandidateApplication::factory()->count(3)->create(['requisition_id' => $requisition->id, 'status' => ApplicationStatus::Active]);

    $facts = app(RecruitmentAnalyticsService::class)->requisitionMetrics($requisition);

    $source = (string) file_get_contents((new ReflectionMethod(RecruitmentAnalyticsService::class, 'requisitionMetrics'))->getFileName());

    expect($facts['active_applications'])->toBe(3)
        ->and($source)->not->toContain('->limit(500)')
        ->and($source)->not->toContain('->take(200)');
});

test('DF-13: the hiring forecast returns the same keys with or without history', function (): void {
    $empty = app(ForecastHiringTool::class)->handle(['target_hires' => 5], $this->viewer)->data;

    expect(array_keys($empty))->toContain('historical_conversion_rate_pct')
        ->and($empty['historical_conversion_rate_pct'])->toBeNull()
        ->and($empty)->not->toHaveKey('conversion_rate_pct');
});

test('DF-14: the turn-up alert compares two back-to-back weeks of whole days', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'Asia/Kolkata'));
    $application = CandidateApplication::factory()->create();

    // Six days ago at 06:00 — outside the old "now minus six days" window, inside this week's days.
    foreach ([InterviewStatus::NoShow, InterviewStatus::NoShow, InterviewStatus::Completed] as $status) {
        Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => $status, 'scheduled_at' => CarbonImmutable::parse('2026-09-18 06:00:00', 'Asia/Kolkata')]);
    }
    foreach (range(1, 3) as $ignored) {
        Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed, 'scheduled_at' => CarbonImmutable::parse('2026-09-12 10:00:00', 'Asia/Kolkata')]);
    }

    $alert = app(RecruitmentActionCenterService::class)->alerts()->firstWhere('key', 'turnup_drop');

    expect($alert['message'] ?? null)->toBe('Turn-up ratio dropped from 100% to 33.3% this week.');
});
