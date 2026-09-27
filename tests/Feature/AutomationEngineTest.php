<?php

use App\Enums\AutomationActionStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationFailureBehavior;
use App\Enums\AutomationScope;
use App\Enums\CandidateStage;
use App\Enums\TimelineVisibility;
use App\Events\InterviewScheduled;
use App\Jobs\RunAutomationExecutionJob;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\CandidateTimelineEvent;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\RecruiterAction;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationRuleService;
use App\Services\CandidateTimelineService;
use App\Services\InterviewService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
    $this->recruiter = Employee::factory()->reportingTo($this->manager)->create();
    User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
});

function engineApplication(Employee $recruiter, array $attributes = []): CandidateApplication
{
    return CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id, 'current_stage' => CandidateStage::Shortlisted, ...$attributes]);
}

function engineInterview(CandidateApplication $application, array $data = []): Interview
{
    return app(InterviewService::class)->schedule($application, ['interviewer_id' => Interviewer::factory()->create()->employee_id, 'scheduled_at' => now()->addDays(3), 'mode' => 'video_call', ...$data]);
}

test('an interview scheduled event runs an active rule and records what happened, against the rule version', function (): void {
    $rule = AutomationRule::factory()->active()->create();

    $interview = engineInterview(engineApplication($this->recruiter));

    $execution = AutomationExecution::query()->sole();
    $action = RecruiterAction::query()->sole();

    expect($execution->status)->toBe(AutomationExecutionStatus::Completed)
        ->and($execution->automation_rule_version_id)->toBe($rule->currentVersion->id)
        ->and($execution->subject_id)->toBe($interview->id)
        ->and($execution->conditions_passed)->toBeTrue()
        ->and($execution->actionExecutions->sole()->status)->toBe(AutomationActionStatus::Completed)
        ->and($action->owner_id)->toBe($this->recruiter->id)
        ->and($action->automation_execution_id)->toBe($execution->id)
        ->and($this->recruiter->user->notifications()->where('data->viewData->execution_id', $execution->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'automation_executed')->exists())->toBeTrue();
});

test('draft and paused rules never execute', function (): void {
    AutomationRule::factory()->create();
    AutomationRule::factory()->create(['status' => 'paused']);

    engineInterview(engineApplication($this->recruiter));

    expect(AutomationExecution::query()->count())->toBe(0);
});

test('a duplicate event creates only one execution and one action', function (): void {
    AutomationRule::factory()->active()->create();
    $interview = engineInterview(engineApplication($this->recruiter));

    InterviewScheduled::dispatch($interview);
    InterviewScheduled::dispatch($interview);

    expect(AutomationExecution::query()->count())->toBe(1)
        ->and(RecruiterAction::query()->count())->toBe(1);
});

test('delivering the same execution job again does not repeat its actions (Phase 8.3)', function (): void {
    AutomationRule::factory()->active()->create();
    engineInterview(engineApplication($this->recruiter));
    $execution = AutomationExecution::query()->sole();

    (new RunAutomationExecutionJob($execution->id))->handle(app(AutomationEngine::class));
    (new RunAutomationExecutionJob($execution->id))->handle(app(AutomationEngine::class));

    expect(RecruiterAction::query()->count())->toBe(1)
        ->and($execution->fresh()->status)->not->toBe(AutomationExecutionStatus::Pending);
});

test('conditions that are not met skip the run with a readable trace', function (): void {
    AutomationRule::factory()->active()->create(['conditions' => ['match' => 'all', 'rules' => [
        ['field' => 'interview.mode_unknown', 'operator' => 'equals', 'value' => 'x'],
    ]]]);
    AutomationRule::factory()->active()->create(['conditions' => ['match' => 'all', 'rules' => [
        ['field' => 'application.stage', 'operator' => 'equals', 'value' => CandidateStage::Selected->value],
    ]]]);

    engineInterview(engineApplication($this->recruiter));

    $executions = AutomationExecution::query()->get();

    expect($executions)->toHaveCount(2)
        ->and($executions->every(fn (AutomationExecution $e) => $e->status === AutomationExecutionStatus::Skipped && $e->skip_reason === 'Conditions not met.'))->toBeTrue()
        ->and($executions->last()->condition_results[0])->toMatchArray(['label' => 'Application: Stage (milestone)', 'passed' => false])
        ->and(RecruiterAction::query()->count())->toBe(0);
});

test('a rule scoped to a department ignores records from other departments', function (): void {
    $department = Department::factory()->create();
    AutomationRule::factory()->active()->create(['scope_type' => AutomationScope::Department, 'scope_id' => $department->id]);

    engineInterview(engineApplication($this->recruiter));
    engineInterview(engineApplication($this->recruiter, ['requisition_id' => RecruitmentRequisition::factory()->create(['department_id' => $department->id])->id]));

    expect(AutomationExecution::query()->count())->toBe(1);
});

test('one failing action leaves the run partially completed and a retry only re-runs the failed action', function (): void {
    AutomationRule::factory()->active()->withActions([
        ['type' => 'create_action', 'action_type' => 'confirm_interview', 'owner' => 'recruiter', 'priority' => 'high', 'title' => 'Confirm'],
        ['type' => 'notify', 'recipient' => 'interviewer', 'priority' => 'medium', 'title' => 'Heads up'],
    ])->create();

    engineInterview(engineApplication($this->recruiter));
    $execution = AutomationExecution::query()->sole();

    expect($execution->status)->toBe(AutomationExecutionStatus::PartiallyCompleted)
        ->and($execution->actionExecutions[1]->status)->toBe(AutomationActionStatus::Failed)
        ->and($execution->failure_reason)->toContain('No active recipient');

    $interviewer = Interview::query()->sole()->interviewer;
    User::factory()->create(['employee_id' => $interviewer->id]);

    app(AutomationEngine::class)->retry($execution);

    expect($execution->fresh()->status)->toBe(AutomationExecutionStatus::Completed)
        ->and($execution->fresh()->retry_count)->toBe(1)
        ->and(RecruiterAction::query()->count())->toBe(1)
        ->and($execution->actionExecutions()->pluck('attempts')->all())->toBe([1, 2]);
});

test('a rule set to stop on failure skips the remaining actions', function (): void {
    AutomationRule::factory()->active()->withActions([
        ['type' => 'notify', 'recipient' => 'interviewer', 'priority' => 'medium', 'title' => 'Heads up'],
        ['type' => 'create_action', 'action_type' => 'confirm_interview', 'owner' => 'recruiter', 'priority' => 'high', 'title' => 'Confirm'],
    ])->create(['failure_behavior' => AutomationFailureBehavior::Stop]);

    engineInterview(engineApplication($this->recruiter));
    $execution = AutomationExecution::query()->sole();

    expect($execution->status)->toBe(AutomationExecutionStatus::Failed)
        ->and($execution->actionExecutions[1]->status)->toBe(AutomationActionStatus::Skipped)
        ->and(RecruiterAction::query()->count())->toBe(0);
});

test('cooldown stops the same rule running again for the same record', function (): void {
    AutomationRule::factory()->active()->create(['trigger' => 'interview.rescheduled', 'cooldown_minutes' => 1440]);
    $interview = engineInterview(engineApplication($this->recruiter));

    app(InterviewService::class)->reschedule($interview, now()->addDays(4));
    $this->travel(1)->minutes();
    app(InterviewService::class)->reschedule($interview->fresh(), now()->addDays(5));

    $statuses = AutomationExecution::query()->orderBy('id')->get();

    expect($statuses->pluck('status')->all())->toBe([AutomationExecutionStatus::Completed, AutomationExecutionStatus::Skipped])
        ->and($statuses->last()->skip_reason)->toContain('Cooldown')
        ->and(RecruiterAction::query()->count())->toBe(1);
});

test('the daily execution limit stops a rule from running more than allowed', function (): void {
    AutomationRule::factory()->active()->create(['max_executions_per_day' => 1]);

    engineInterview(engineApplication($this->recruiter));
    engineInterview(engineApplication($this->recruiter));

    expect(AutomationExecution::query()->orderBy('id')->pluck('status')->all())->toBe([AutomationExecutionStatus::Completed, AutomationExecutionStatus::Skipped])
        ->and(AutomationExecution::query()->latest('id')->first()->skip_reason)->toContain('Daily limit');
});

test('a rule whose action re-triggers itself is stopped by loop prevention and audited', function (): void {
    AutomationRule::factory()->active()->on('candidate.stage_changed')->withActions([
        ['type' => 'move_stage', 'stage' => CandidateStage::Connected->value],
    ])->create();

    $application = engineApplication($this->recruiter, ['current_stage' => CandidateStage::Sourced]);
    app(StageTransitionService::class)->transitionTo($application, CandidateStage::ContactAttempted);

    $executions = AutomationExecution::query()->orderBy('id')->get();

    expect($application->fresh()->current_stage)->toBe(CandidateStage::Connected)
        ->and($executions)->toHaveCount(2)
        ->and($executions[1]->status)->toBe(AutomationExecutionStatus::Skipped)
        ->and($executions[1]->skip_reason)->toContain('Loop prevented')
        ->and($executions[1]->parent_execution_id)->toBe($executions[0]->id)
        ->and($executions[1]->depth)->toBe(1)
        ->and(AuditLog::query()->where('action', 'automation_loop_prevented')->exists())->toBeTrue();
});

test('chains of different rules stop at the maximum chain depth', function (): void {
    config(['automation.max_chain_depth' => 1]);
    $stageIs = fn (CandidateStage $stage): array => ['match' => 'all', 'rules' => [['field' => 'event.new_stage', 'operator' => 'equals', 'value' => $stage->value]]];

    AutomationRule::factory()->active()->on('candidate.stage_changed', $stageIs(CandidateStage::ContactAttempted))->withActions([['type' => 'move_stage', 'stage' => CandidateStage::Connected->value]])->create();
    AutomationRule::factory()->active()->on('candidate.stage_changed', $stageIs(CandidateStage::Connected))->withActions([['type' => 'move_stage', 'stage' => CandidateStage::Interested->value]])->create();

    $application = engineApplication($this->recruiter, ['current_stage' => CandidateStage::Sourced]);
    app(StageTransitionService::class)->transitionTo($application, CandidateStage::ContactAttempted);

    expect($application->fresh()->current_stage)->toBe(CandidateStage::Connected)
        ->and(AutomationExecution::query()->where('skip_reason', 'like', '%maximum automation chain depth%')->exists())->toBeTrue();
});

test('a run keeps using the rule version it was created with after the rule is edited', function (): void {
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $rule = AutomationRule::factory()->create(['timing' => ['mode' => 'delay', 'amount' => 2, 'unit' => 'hours', 'anchor' => 'event']]);
    app(AutomationRuleService::class)->activate($rule, $admin);

    engineInterview(engineApplication($this->recruiter));
    $execution = AutomationExecution::query()->sole();

    app(AutomationRuleService::class)->update($rule, ['actions' => [['type' => 'add_audit_event', 'note' => 'changed']]], $admin);
    $this->travel(3)->hours();
    $this->artisan('recruitment:automation:process')->assertSuccessful();

    expect($rule->fresh()->version)->toBe(2)
        ->and($execution->fresh()->status)->toBe(AutomationExecutionStatus::Completed)
        ->and($execution->fresh()->ruleVersion->version)->toBe(1)
        ->and(RecruiterAction::query()->count())->toBe(1);
});

test('a run timed before an interview waits, and is dropped if the interview is rescheduled', function (): void {
    AutomationRule::factory()->active()->create(['timing' => ['mode' => 'delay', 'amount' => 24, 'unit' => 'hours', 'direction' => 'before', 'anchor' => 'interview.scheduled_at']]);
    $interview = engineInterview(engineApplication($this->recruiter), ['scheduled_at' => now()->addDays(3)->startOfHour()]);
    $execution = AutomationExecution::query()->sole();

    expect($execution->status)->toBe(AutomationExecutionStatus::Pending)
        ->and($execution->scheduled_for->equalTo($interview->scheduled_at->copy()->subDay()))->toBeTrue();

    app(InterviewService::class)->reschedule($interview, now()->addDays(5));
    $this->travel(2)->days();
    $this->artisan('recruitment:automation:process')->assertSuccessful();

    expect($execution->fresh()->status)->toBe(AutomationExecutionStatus::Skipped)
        ->and($execution->fresh()->skip_reason)->toContain('rescheduled')
        ->and(RecruiterAction::query()->count())->toBe(0);
});

test('pausing a rule cancels its runs that have not happened yet', function (): void {
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $rule = AutomationRule::factory()->active()->create(['timing' => ['mode' => 'delay', 'amount' => 1, 'unit' => 'hours', 'anchor' => 'event']]);
    engineInterview(engineApplication($this->recruiter));

    app(AutomationRuleService::class)->pause($rule, $admin);
    $this->travel(2)->hours();
    $this->artisan('recruitment:automation:process')->assertSuccessful();

    expect(AutomationExecution::query()->sole()->status)->toBe(AutomationExecutionStatus::Cancelled)
        ->and(RecruiterAction::query()->count())->toBe(0);
});

test('a run whose worker died is marked failed so it can be retried', function (): void {
    $execution = AutomationExecution::factory()->create(['status' => AutomationExecutionStatus::Running]);
    $execution->forceFill(['started_at' => now()->subHours(2)])->save();

    $this->artisan('recruitment:automation:process')->assertSuccessful();

    expect($execution->fresh()->status)->toBe(AutomationExecutionStatus::Failed)
        ->and($execution->fresh()->isRetryable())->toBeTrue();
});

test('timeline notes written by automation stay internal and never reach the candidate portal', function (): void {
    AutomationRule::factory()->active()->withActions([['type' => 'add_timeline_event', 'title' => 'Automation: recruiter chased interviewer']])->create();

    $interview = engineInterview(engineApplication($this->recruiter));
    $candidate = $interview->candidateApplication->candidate;

    expect(CandidateTimelineEvent::query()->where('title', 'Automation: recruiter chased interviewer')->sole()->visibility)->toBe(TimelineVisibility::Internal)
        ->and(app(CandidateTimelineService::class)->forPortal($candidate)->pluck('title')->all())->not->toContain('Automation: recruiter chased interviewer');
});
