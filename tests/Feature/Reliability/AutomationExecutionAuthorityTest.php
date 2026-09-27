<?php

use App\Enums\AutomationActionStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationScope;
use App\Enums\CandidateStage;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationTrigger;
use App\Enums\EscalationStatus;
use App\Enums\TemplateStatus;
use App\Jobs\ProcessOwnershipHandoffJob;
use App\Models\AuditLog;
use App\Models\AutomationEscalation;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\RecruiterAction;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationRuleService;
use App\Services\Automation\EscalationService;
use App\Services\Communication\CommunicationTemplateService;
use App\Services\Identity\StaffAccessService;
use App\Services\InterviewService;
use Database\Seeders\RolePermissionSeeder;

/**
 * Phase 8.7 (D8.7-009/010/013/016/022/023, SEC-87-05/06): automation acts on its owner's current
 * authority over the record as it is now — scope, visibility and each action's permission are
 * re-checked when a delayed run or an escalation fires.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
    $this->recruiter = Employee::factory()->reportingTo($this->manager)->create();
    User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
    $this->otherRecruiter = Employee::factory()->create();
    $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
});

function authorityInterview(Employee $recruiter): Interview
{
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id, 'current_stage' => CandidateStage::Shortlisted]);

    return app(InterviewService::class)->schedule($application, ['interviewer_id' => Interviewer::factory()->create()->employee_id, 'scheduled_at' => now()->addDays(3), 'mode' => 'video_call']);
}

function delayedRule(array $attributes = []): AutomationRule
{
    return AutomationRule::factory()->active()->create(['timing' => ['mode' => 'delay', 'amount' => 2, 'unit' => 'hours', 'anchor' => 'event'], ...$attributes]);
}

function runDue(): void
{
    test()->travel(3)->hours();
    test()->artisan('recruitment:automation:process')->assertSuccessful();
}

test('a delayed run skips a record that left the rule\'s scope, and says so', function (): void {
    delayedRule(['scope_type' => AutomationScope::Recruiter, 'scope_id' => $this->recruiter->id]);
    $interview = authorityInterview($this->recruiter);

    CandidateApplication::query()->whereKey($interview->candidate_application_id)->update(['recruiter_id' => $this->otherRecruiter->id]);
    runDue();

    $execution = AutomationExecution::query()->sole();

    expect($execution->status)->toBe(AutomationExecutionStatus::Skipped)
        ->and($execution->skip_reason)->toContain('no longer within the rule\'s scope')
        ->and(RecruiterAction::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'automation_skipped_authority')->where('auditable_id', $execution->id)->exists())->toBeTrue();
});

test('a delayed run skips a record its owner can no longer see', function (): void {
    $owner = $this->manager->user;
    $owner->givePermissionTo('automation.organization');
    delayedRule(['owner_id' => $owner->id]);
    $interview = authorityInterview($this->recruiter);

    CandidateApplication::query()->whereKey($interview->candidate_application_id)->update(['recruiter_id' => $this->otherRecruiter->id]);
    runDue();

    expect(AutomationExecution::query()->sole()->skip_reason)->toContain('no longer visible to the rule\'s owner')
        ->and(RecruiterAction::query()->count())->toBe(0);
});

test('an action whose permission the owner lost is not taken', function (): void {
    $rule = delayedRule();
    authorityInterview($this->recruiter);

    $rule->owner->revokePermissionTo('actions.manage');
    runDue();

    $execution = AutomationExecution::query()->sole();

    expect($execution->status)->toBe(AutomationExecutionStatus::Skipped)
        ->and($execution->actionExecutions->sole()->status)->toBe(AutomationActionStatus::Skipped)
        ->and($execution->actionExecutions->sole()->summary)->toContain('actions.manage')
        ->and(RecruiterAction::query()->count())->toBe(0);
});

test('editing a rule cancels its scheduled runs by default, with the reason audited', function (): void {
    $rule = delayedRule();
    app(AutomationRuleService::class)->activate($rule, $this->admin, 'Reviewed');
    authorityInterview($this->recruiter);

    app(AutomationRuleService::class)->update($rule, ['actions' => [['type' => 'add_audit_event', 'note' => 'changed']]], $this->admin, 'The old action was wrong');
    runDue();

    $execution = AutomationExecution::query()->sole();
    $audit = AuditLog::query()->where('action', 'automation_pending_runs_cancelled')->sole();

    expect($execution->status)->toBe(AutomationExecutionStatus::Cancelled)
        ->and($execution->skip_reason)->toContain('The old action was wrong')
        ->and($audit->reason)->toBe('The old action was wrong')
        ->and($audit->changes['count'])->toBe(1)
        ->and(RecruiterAction::query()->count())->toBe(0);
});

test('the automation sweep pauses a rule whose owner lost access, even if no handoff ran', function (): void {
    $rule = AutomationRule::factory()->active()->create();

    app(StaffAccessService::class)->suspend($rule->owner, null, 'Left the company');
    AutomationRule::query()->whereKey($rule->id)->update(['status' => AutomationRuleStatus::Active->value]);
    $this->artisan('recruitment:automation:process')->assertSuccessful();

    expect($rule->fresh()->status)->toBe(AutomationRuleStatus::Paused)
        ->and(AuditLog::query()->where('action', 'automation_rule_paused_authority')->where('auditable_id', $rule->id)->exists())->toBeTrue();
});

test('an escalation re-checks its owner and the record before it fires, and acts as automation', function (): void {
    $rule = AutomationRule::factory()->active()->create(['escalation' => ['steps' => [['after' => 2, 'unit' => 'hours', 'target' => 'role:manager']], 'stop_conditions' => null]]);
    authorityInterview($this->recruiter);
    $interview = authorityInterview($this->recruiter);
    [$kept, $moved] = AutomationEscalation::query()->orderBy('id')->get()->all();

    CandidateApplication::query()->whereKey($interview->candidate_application_id)->update(['recruiter_id' => $this->otherRecruiter->id]);
    $rule->update(['scope_type' => AutomationScope::Recruiter, 'scope_id' => $this->recruiter->id]);
    $this->travel(3)->hours();

    app(EscalationService::class)->process($moved->id);
    app(EscalationService::class)->process($kept->id);

    $sentAudit = AuditLog::query()->where('action', 'automation_escalated')->sole();

    expect($moved->fresh()->status)->toBe(EscalationStatus::Cancelled)
        ->and($moved->fresh()->outcome)->toContain('no longer within the rule\'s scope')
        ->and($kept->fresh()->status)->toBe(EscalationStatus::Sent)
        ->and($sentAudit->actor_kind)->toBe('automation')
        ->and($sentAudit->on_behalf_of_user_id)->toBe($rule->owner_id);
});

test('an escalation whose owner lost the authority to run automation is cancelled and its rule paused; one outside the effective dates is cancelled', function (): void {
    $rule = AutomationRule::factory()->active()->create(['escalation' => ['steps' => [['after' => 2, 'unit' => 'hours', 'target' => 'role:manager']], 'stop_conditions' => null]]);
    authorityInterview($this->recruiter);
    authorityInterview($this->recruiter);
    [$first, $second] = AutomationEscalation::query()->orderBy('id')->get()->all();
    $this->travel(3)->hours();

    $rule->update(['effective_until' => now()->subMinute()]);
    app(EscalationService::class)->process($first->id);

    $rule->update(['effective_until' => null]);
    $rule->owner->revokePermissionTo('automation.activate');
    app(EscalationService::class)->process($second->id);

    expect($first->fresh()->status)->toBe(EscalationStatus::Cancelled)
        ->and($first->fresh()->outcome)->toContain('effective dates')
        ->and($second->fresh()->status)->toBe(EscalationStatus::Cancelled)
        ->and($second->fresh()->outcome)->toContain('paused')
        ->and($rule->fresh()->status)->toBe(AutomationRuleStatus::Paused);
});

test('an escalation\'s candidate message counts toward the daily automated-message cap', function (): void {
    config(['mail.default' => 'array', 'mail.from.address' => 'hiring@example.com', 'automation.max_candidate_messages_per_day' => 1]);
    app(CommunicationTemplateService::class)->create(['key' => 'still_waiting', 'name' => 'Still waiting', 'channel' => CommunicationChannel::Email, 'subject' => 'Update', 'body' => 'We are on it.', 'status' => TemplateStatus::Active]);
    AutomationRule::factory()->active()->create(['escalation' => ['steps' => [['after' => 2, 'unit' => 'hours', 'target' => 'role:manager', 'candidate_template' => 'still_waiting']], 'stop_conditions' => null]]);
    $interview = authorityInterview($this->recruiter);
    CandidateCommunication::factory()->create(['candidate_id' => $interview->candidateApplication->candidate_id, 'trigger' => CommunicationTrigger::Automation]);

    $this->travel(3)->hours();
    app(EscalationService::class)->process(AutomationEscalation::query()->sole()->id);

    expect(CandidateCommunication::query()->where('trigger', CommunicationTrigger::Automation)->count())->toBe(1);
});

test('a run whose worker died before any action started is run again once; a second death fails it', function (): void {
    $rule = delayedRule();
    authorityInterview($this->recruiter);
    $execution = AutomationExecution::query()->sole();
    AutomationExecution::query()->whereKey($execution->id)->update(['status' => AutomationExecutionStatus::Running->value, 'started_at' => now()->subHours(2)]);

    app(AutomationEngine::class)->processDue(0);

    expect($execution->fresh()->status)->toBe(AutomationExecutionStatus::Pending)
        ->and(AuditLog::query()->where('action', 'automation_run_reclaimed')->exists())->toBeTrue();

    AutomationExecution::query()->whereKey($execution->id)->update(['status' => AutomationExecutionStatus::Running->value, 'started_at' => now()->subHours(2)]);
    app(AutomationEngine::class)->processDue(0);

    expect($execution->fresh()->status)->toBe(AutomationExecutionStatus::Failed)
        ->and($rule->fresh()->status)->toBe(AutomationRuleStatus::Active);
});

test('a handoff that exhausts its retries is recorded and raised to platform administrators', function (): void {
    $departed = User::factory()->create();
    $platformAdmin = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->givePermissionTo('settings.manage');

    (new ProcessOwnershipHandoffJob($departed->id, null, 'suspended', 'handoff-test'))->failed(new RuntimeException('database went away'));

    expect(AuditLog::query()->where('action', 'ownership_handoff_failed')->where('auditable_id', $departed->id)->exists())->toBeTrue()
        ->and($platformAdmin->notifications()->where('data->title', 'like', '%Ownership handoff failed%')->count())->toBe(1)
        ->and((new ProcessOwnershipHandoffJob($departed->id, null, 'suspended', 'x'))->backoff)->toBe([30, 120]);
});
