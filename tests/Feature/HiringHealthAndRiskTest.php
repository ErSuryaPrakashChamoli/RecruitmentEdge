<?php

use App\Enums\ApplicationStatus;
use App\Enums\AutomationRuleStatus;
use App\Enums\CandidateStage;
use App\Enums\HealthStatus;
use App\Enums\HiringRiskStatus;
use App\Enums\HiringRiskType;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Enums\RiskSeverity;
use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\HiringHealthSnapshot;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruiterAction;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Intelligence\HiringHealthService;
use App\Services\Intelligence\HiringRiskRadar;
use App\Services\NextBestAction\NextBestActionService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->health = app(HiringHealthService::class);
    $this->radar = app(HiringRiskRadar::class);
    $this->recruiter = Employee::factory()->create();
    User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
});

function healthRequisition(Employee $recruiter, array $attributes = []): RecruitmentRequisition
{
    $requisition = RecruitmentRequisition::factory()->create([
        'status' => RequisitionStatus::Open,
        'openings' => 1,
        'opening_date' => now()->subDays(5),
        'skills' => ['PHP'],
        'experience_min' => 1,
        'experience_max' => 5,
        'qualification' => 'B.Tech',
        'salary_min' => 10,
        'salary_max' => 20,
        'target_joining_date' => now()->addMonth(),
        ...$attributes,
    ]);
    $requisition->recruiters()->attach($recruiter->id);

    return $requisition;
}

function healthMetric(HiringHealthSnapshot $snapshot, string $key): array
{
    return $snapshot->metric($key);
}

test('an open requisition with no candidates is critical, and every metric shows value, threshold and evidence', function (): void {
    $snapshot = $this->health->refresh(healthRequisition($this->recruiter));

    expect($snapshot->status)->toBe(HealthStatus::Critical)
        ->and(healthMetric($snapshot, 'pipeline_depth'))->toMatchArray(['status' => 'breach', 'threshold' => 'at least 2 (2 per open position)'])
        ->and($snapshot->evidence()->where('subject_key', 'pipeline_depth')->exists())->toBeTrue()
        ->and(collect($snapshot->metrics)->every(fn (array $m) => collect(['key', 'label', 'value', 'display', 'threshold', 'status', 'explanation'])->every(fn (string $k) => array_key_exists($k, $m))))->toBeTrue()
        ->and($snapshot->evidence()->distinct()->count('subject_key'))->toBe(count($snapshot->metrics));
});

test('a healthy requisition with a good pipeline is healthy or on watch, never critical', function (): void {
    $requisition = healthRequisition($this->recruiter);
    CandidateApplication::factory()->count(3)->create(['requisition_id' => $requisition->id, 'recruiter_id' => $this->recruiter->id, 'last_activity_at' => now()]);

    $snapshot = $this->health->refresh($requisition);

    expect(in_array($snapshot->status, [HealthStatus::Healthy, HealthStatus::Watch], true))->toBeTrue()
        ->and(healthMetric($snapshot, 'pipeline_depth')['status'])->toBe('ok');
});

test('ageing, stalled candidates and missing data are measured against the settings', function (): void {
    $requisition = healthRequisition($this->recruiter, ['opening_date' => now()->subDays(50), 'skills' => [], 'qualification' => null, 'salary_min' => null, 'salary_max' => null, 'target_joining_date' => null]);
    CandidateApplication::factory()->count(3)->create(['requisition_id' => $requisition->id, 'recruiter_id' => $this->recruiter->id, 'last_activity_at' => now()->subDays(20)]);

    $snapshot = $this->health->refresh($requisition);

    expect(healthMetric($snapshot, 'days_open')['status'])->toBe('breach')
        ->and(healthMetric($snapshot, 'stalled_candidates')['status'])->toBe('breach')
        ->and(healthMetric($snapshot, 'data_completeness')['display'])->toContain('missing: Skills');
});

test('too little requisition data gives insufficient data, not a verdict', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'skills' => [], 'experience_min' => null, 'experience_max' => null, 'qualification' => null, 'salary_min' => null, 'salary_max' => null, 'target_joining_date' => null, 'location_id' => null]);

    expect($this->health->refresh($requisition)->status)->toBe(HealthStatus::InsufficientData);
});

test('a fresh snapshot is reused, a forced refresh keeps history', function (): void {
    $requisition = healthRequisition($this->recruiter);
    $first = $this->health->refresh($requisition);

    expect($this->health->refresh($requisition)->id)->toBe($first->id);

    $second = $this->health->refresh($requisition, force: true);

    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->is_current)->toBeFalse()
        ->and(HiringHealthSnapshot::query()->where('requisition_id', $requisition->id)->count())->toBe(2);
});

test('the radar opens evidence-backed requisition risks with an Action Center item, once', function (): void {
    healthRequisition($this->recruiter, ['opening_date' => now()->subDays(60)]);

    $first = $this->radar->scan();
    $second = $this->radar->scan();
    $risk = HiringRisk::query()->where('type', HiringRiskType::ThinPipeline)->sole();

    expect($first['opened'])->toBeGreaterThanOrEqual(2)
        ->and($second['opened'])->toBe(0)
        ->and($risk->severity)->toBe(RiskSeverity::Critical)
        ->and($risk->owner_id)->toBe($this->recruiter->id)
        ->and($risk->evidence()->where('subject_key', 'pipeline_depth')->exists())->toBeTrue()
        ->and(RecruiterAction::query()->where('subject_type', $risk->getMorphClass())->where('subject_id', $risk->id)->count())->toBe(1)
        ->and(HiringRisk::query()->where('type', HiringRiskType::RequisitionAging)->exists())->toBeTrue();
});

test('risks that are no longer observed are auto-resolved', function (): void {
    $requisition = healthRequisition($this->recruiter);
    $this->radar->scan();
    $risk = HiringRisk::query()->where('type', HiringRiskType::ThinPipeline)->sole();

    CandidateApplication::factory()->count(3)->create(['requisition_id' => $requisition->id, 'recruiter_id' => $this->recruiter->id, 'last_activity_at' => now()]);
    $this->travel(7)->hours();
    $counts = $this->radar->scan();

    expect($counts['resolved'])->toBeGreaterThanOrEqual(1)
        ->and($risk->fresh()->status)->toBe(HiringRiskStatus::Resolved)
        ->and($risk->fresh()->resolution)->toContain('No longer detected')
        ->and(AuditLog::query()->where('action', 'hiring_risk_auto_resolved')->exists())->toBeTrue();
});

test('dismissing a risk needs a reason and suppresses it for a week', function (): void {
    healthRequisition($this->recruiter);
    $this->radar->scan();
    $risk = HiringRisk::query()->where('type', HiringRiskType::ThinPipeline)->sole();
    $user = User::factory()->create()->assignRole('vp_hr');

    expect(fn () => $this->radar->dismiss($risk, $user, ''))->toThrow(DomainException::class);

    $this->radar->dismiss($risk, $user, 'Role on hold informally');
    $this->radar->scan();
    expect(HiringRisk::query()->where('type', HiringRiskType::ThinPipeline)->count())->toBe(1);

    $this->travel(8)->days();
    $this->radar->scan();
    expect(HiringRisk::query()->where('type', HiringRiskType::ThinPipeline)->open()->count())->toBe(1);
});

test('entity risks: red joinings, offers near expiry and interviewer backlogs', function (): void {
    $application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::OfferAccepted]);
    CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Expected, 'expected_doj' => now()->subDay()]);
    Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Released, 'offer_expiry' => now()->addDay()->toDateString()]);
    $interviewer = Employee::factory()->create();
    Interview::factory()->count(3)->create(['interviewer_id' => $interviewer->id, 'status' => InterviewStatus::Scheduled, 'scheduled_at' => now()->subDays(2)]);

    $this->radar->scan();

    expect(HiringRisk::query()->where('type', HiringRiskType::JoiningRisk)->sole())
        ->severity->toBe(RiskSeverity::Critical)
        ->owner_id->toBe($this->recruiter->id)
        ->and(HiringRisk::query()->where('type', HiringRiskType::OfferRisk)->where('subject_type', (new Offer)->getMorphClass())->exists())->toBeTrue()
        ->and(HiringRisk::query()->where('type', HiringRiskType::InterviewerBottleneck)->sole()->subject_id)->toBe($interviewer->id);
});

test('a new risk triggers automation rules built on intelligence.risk_detected', function (): void {
    AutomationRule::factory()->create([
        'status' => AutomationRuleStatus::Active,
        'trigger' => 'intelligence.risk_detected',
        'conditions' => ['match' => 'all', 'rules' => [['field' => 'risk.severity', 'operator' => 'equals', 'value' => 'critical']]],
        'actions' => [['type' => 'add_audit_event', 'note' => 'Critical risk escalated']],
    ]);
    healthRequisition($this->recruiter);

    $this->radar->scan();

    expect(AuditLog::query()->where('action', 'automation_note')->where('auditable_type', HiringRisk::class)->exists())->toBeTrue();
});

test('open risks become evidence-backed Next Best Actions', function (): void {
    $application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'status' => ApplicationStatus::Active, 'last_activity_at' => now()]);
    CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Expected, 'expected_doj' => now()->subDay()]);
    $this->radar->scan();

    $top = app(NextBestActionService::class)->forApplication($application->fresh())->first(fn ($a) => str_starts_with($a->source, 'risk:'));

    expect($top)->not->toBeNull()
        ->and($top->evidence)->not->toBeEmpty()
        ->and($top->reason)->toContain('Risk Radar');
});
