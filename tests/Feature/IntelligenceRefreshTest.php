<?php

use App\Enums\AutomationRuleStatus;
use App\Enums\IntelligenceAiStatus;
use App\Enums\RequisitionStatus;
use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\HiringHealthSnapshot;
use App\Models\HiringRisk;
use App\Models\RecruitmentRequisition;
use App\Models\TalentSignalSnapshot;
use App\Models\User;
use App\Services\AI\Gateway\AiGateway;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\ConditionEvaluator;
use App\Services\Intelligence\RoleDnaService;
use App\Services\Intelligence\TalentSignalService;
use App\Services\RequisitionApprovalService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the hourly refresh computes health, signals and risks for open requisitions only, and is idempotent', function (): void {
    $open = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'skills' => ['PHP']]);
    CandidateApplication::factory()->create(['requisition_id' => $open->id]);
    $closed = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed]);

    $this->artisan('intelligence:refresh', ['--dry-run' => true])->expectsOutputToContain('[dry run] 1 requisition(s); 1 with stale Hiring Health')->assertSuccessful();
    expect(HiringHealthSnapshot::query()->count())->toBe(0);

    $this->artisan('intelligence:refresh')->expectsOutputToContain('1 health snapshot(s), 1 talent signal(s) refreshed')->assertSuccessful();
    $this->artisan('intelligence:refresh')->expectsOutputToContain('0 health snapshot(s), 0 talent signal(s) refreshed')->assertSuccessful();

    expect(HiringHealthSnapshot::query()->where('requisition_id', $closed->id)->exists())->toBeFalse()
        ->and(TalentSignalSnapshot::query()->count())->toBe(1)
        ->and(HiringRisk::query()->where('requisition_id', $open->id)->exists())->toBeTrue();
});

test('intelligence facts can be used in automation conditions', function (): void {
    $application = CandidateApplication::factory()->create();
    $application->requisition->update(['skills' => ['PHP']]);
    $application->candidate->update(['skills' => ['PHP'], 'total_experience' => 3]);
    app(TalentSignalService::class)->refresh($application->fresh());

    $context = AutomationContext::for($application->fresh(), 'candidate.stage_changed');
    $result = app(ConditionEvaluator::class)->evaluate(['match' => 'all', 'rules' => [['field' => 'application.talent_signal', 'operator' => 'in', 'value' => ['strong', 'moderate']]]], $context);

    expect($result['passed'])->toBeTrue();
});

test('requisition status changes are an automation trigger', function (): void {
    AutomationRule::factory()->create([
        'status' => AutomationRuleStatus::Active,
        'trigger' => 'requisition.status_changed',
        'conditions' => ['match' => 'all', 'rules' => [['field' => 'event.new_requisition_status', 'operator' => 'equals', 'value' => 'on_hold']]],
        'actions' => [['type' => 'add_audit_event', 'note' => 'Requisition put on hold']],
    ]);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->actingAs($user);
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);

    app(RequisitionApprovalService::class)->moveTo($requisition, RequisitionStatus::OnHold, $user->employee, 'Budget review');

    expect(AuditLog::query()->where('action', 'automation_note')->where('auditable_type', RecruitmentRequisition::class)->exists())->toBeTrue();
});

test('the refresh expires AI requests that never completed, without calling AI, so they can be asked for again', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed]);
    $stale = app(RoleDnaService::class)->profileFor($requisition);
    $stale->forceFill(['ai_status' => IntelligenceAiStatus::Processing, 'ai_requested_at' => now()->subMinutes(61)])->save();
    $recent = app(RoleDnaService::class)->profileFor(RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed]));
    $recent->forceFill(['ai_status' => IntelligenceAiStatus::Processing, 'ai_requested_at' => now()->subMinutes(5)])->save();
    app()->instance(AiGateway::class, Mockery::mock(AiGateway::class)->shouldNotReceive('structured', 'generate')->getMock());

    $this->artisan('intelligence:refresh')->expectsOutputToContain('1 stale AI request(s) expired')->assertSuccessful();

    expect($stale->fresh()->ai_status)->toBe(IntelligenceAiStatus::Failed)
        ->and($stale->fresh()->ai_error)->toContain('did not complete')
        ->and($recent->fresh()->ai_status)->toBe(IntelligenceAiStatus::Processing);
});
