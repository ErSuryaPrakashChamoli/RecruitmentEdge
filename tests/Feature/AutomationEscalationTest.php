<?php

use App\Enums\AutomationExecutionStatus;
use App\Enums\CandidateStage;
use App\Enums\EmployeeStatus;
use App\Enums\EscalationStatus;
use App\Enums\InterviewStatus;
use App\Enums\RecruiterActionStatus;
use App\Models\AuditLog;
use App\Models\AutomationEscalation;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\RecruiterAction;
use App\Models\User;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\RecipientResolver;
use App\Services\InterviewService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $chain = [];
    $parent = null;

    foreach (['chro', 'vp_hr', 'manager', 'assistant_manager', 'recruiter'] as $role) {
        $employee = Employee::factory()->create(['reports_to_id' => $parent?->id]);
        User::factory()->create(['employee_id' => $employee->id])->assignRole($role);
        $chain[$role] = $employee;
        $parent = $employee;
    }

    $this->chain = $chain;
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $chain['recruiter']->id, 'current_stage' => CandidateStage::Shortlisted]);
});

function escalationInterview(CandidateApplication $application): Interview
{
    return app(InterviewService::class)->schedule($application, ['interviewer_id' => Interviewer::factory()->create()->employee_id, 'scheduled_at' => now()->addDays(3), 'mode' => 'video_call']);
}

/**
 * @param  array<int, array<string, mixed>>  $steps
 */
function escalationRule(array $steps, ?array $stopConditions = null): AutomationRule
{
    return AutomationRule::factory()->active()->create(['escalation' => ['steps' => $steps, 'stop_conditions' => $stopConditions]]);
}

function notificationsFor(Employee $employee): int
{
    return $employee->user->notifications()->where('data->viewData->category', 'Escalation')->count();
}

test('each hierarchy level is resolved through the management chain', function (): void {
    $context = AutomationContext::for($this->application, 'candidate.stage_changed');
    $resolver = app(RecipientResolver::class);

    expect($resolver->resolve('recruiter', $context)->id)->toBe($this->chain['recruiter']->id)
        ->and($resolver->resolve('reports_to', $context)->id)->toBe($this->chain['assistant_manager']->id)
        ->and($resolver->resolve('levels_up:2', $context)->id)->toBe($this->chain['manager']->id)
        ->and($resolver->resolve('role:assistant_manager', $context)->id)->toBe($this->chain['assistant_manager']->id)
        ->and($resolver->resolve('role:manager', $context)->id)->toBe($this->chain['manager']->id)
        ->and($resolver->resolve('role:vp_hr', $context)->id)->toBe($this->chain['vp_hr']->id)
        ->and($resolver->resolve('role:chro', $context)->id)->toBe($this->chain['chro']->id);
});

test('inactive managers are skipped and an inactive recruiter falls back to their manager', function (): void {
    lifecycleFixture(fn () => $this->chain['assistant_manager']->update(['status' => EmployeeStatus::Inactive]));
    lifecycleFixture(fn () => $this->chain['recruiter']->update(['status' => EmployeeStatus::Inactive]));
    $context = AutomationContext::for($this->application->fresh(), 'candidate.stage_changed');
    $resolver = app(RecipientResolver::class);

    expect($resolver->resolve('reports_to', $context)->id)->toBe($this->chain['manager']->id)
        ->and($resolver->resolve('recruiter', $context)->id)->toBe($this->chain['manager']->id)
        ->and($resolver->resolve('role:assistant_manager', $context))->toBeNull();
});

test('escalation steps are scheduled and sent up the chain when due', function (): void {
    escalationRule([
        ['after' => 2, 'unit' => 'hours', 'target' => 'role:manager', 'priority' => 'high', 'create_action' => true],
        ['after' => 24, 'unit' => 'hours', 'target' => 'role:chro', 'priority' => 'critical'],
    ]);
    escalationInterview($this->application);

    expect(AutomationEscalation::query()->where('status', EscalationStatus::Pending)->count())->toBe(2);

    $this->travel(3)->hours();
    $this->artisan('recruitment:automation:process')->assertSuccessful();

    $first = AutomationEscalation::query()->where('step', 1)->sole();

    expect($first->status)->toBe(EscalationStatus::Sent)
        ->and($first->recipient_employee_id)->toBe($this->chain['manager']->id)
        ->and(notificationsFor($this->chain['manager']))->toBe(1)
        ->and(RecruiterAction::query()->where('owner_id', $this->chain['manager']->id)->exists())->toBeTrue()
        ->and(AutomationEscalation::query()->where('step', 2)->sole()->status)->toBe(EscalationStatus::Pending)
        ->and(AuditLog::query()->where('action', 'automation_escalated')->count())->toBe(1);

    $this->travel(1)->day();
    $this->artisan('recruitment:automation:process')->assertSuccessful();

    expect(notificationsFor($this->chain['chro']))->toBe(1);
});

test('escalation stops when the stop condition is met', function (): void {
    escalationRule(
        [['after' => 2, 'unit' => 'hours', 'target' => 'reports_to', 'priority' => 'high']],
        ['match' => 'all', 'rules' => [['field' => 'interview.status', 'operator' => 'equals', 'value' => InterviewStatus::Confirmed->value]]],
    );
    $interview = escalationInterview($this->application);

    app(InterviewService::class)->confirm($interview);
    $this->travel(3)->hours();
    $this->artisan('recruitment:automation:process')->assertSuccessful();

    $escalation = AutomationEscalation::query()->sole();

    expect($escalation->status)->toBe(EscalationStatus::Stopped)
        ->and($escalation->outcome)->toContain('Resolved')
        ->and(notificationsFor($this->chain['assistant_manager']))->toBe(0)
        ->and(AuditLog::query()->where('action', 'automation_escalation_stopped')->exists())->toBeTrue();
});

test('escalation stops when the rule\'s action is completed', function (): void {
    escalationRule([['after' => 2, 'unit' => 'hours', 'target' => 'reports_to'], ['after' => 4, 'unit' => 'hours', 'target' => 'role:manager']]);
    escalationInterview($this->application);

    RecruiterAction::query()->sole()->forceFill(['status' => RecruiterActionStatus::Completed])->save();
    $this->travel(5)->hours();
    $this->artisan('recruitment:automation:process')->assertSuccessful();

    expect(AutomationEscalation::query()->pluck('status')->unique()->all())->toBe([EscalationStatus::Stopped])
        ->and(notificationsFor($this->chain['assistant_manager']))->toBe(0);
});

test('an escalation with nobody to escalate to fails visibly instead of silently', function (): void {
    lifecycleFixture(fn () => $this->chain['chro']->update(['status' => EmployeeStatus::Inactive]));
    escalationRule([['after' => 0, 'unit' => 'hours', 'target' => 'role:chro']]);
    escalationInterview($this->application);

    $this->artisan('recruitment:automation:process')->assertSuccessful();

    expect(AutomationEscalation::query()->sole())
        ->status->toBe(EscalationStatus::Failed)
        ->outcome->toContain('No active recipient');
});

test('the escalate-now action notifies the target immediately and records it', function (): void {
    AutomationRule::factory()->active()->withActions([['type' => 'escalate', 'target' => 'role:vp_hr', 'priority' => 'critical', 'message' => 'Urgent']])->create();
    escalationInterview($this->application);

    expect(AutomationExecution::query()->sole()->status)->toBe(AutomationExecutionStatus::Completed)
        ->and(AutomationEscalation::query()->sole()->status)->toBe(EscalationStatus::Sent)
        ->and(notificationsFor($this->chain['vp_hr']))->toBe(1);
});

test('cancelling an execution stops its pending escalation', function (): void {
    escalationRule([['after' => 2, 'unit' => 'hours', 'target' => 'reports_to']]);
    escalationInterview($this->application);

    app(AutomationEngine::class)->cancel(AutomationExecution::query()->sole(), User::query()->first(), 'No longer needed');

    expect(AutomationEscalation::query()->sole()->status)->toBe(EscalationStatus::Stopped);
});
