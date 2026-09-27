<?php

use App\Enums\AutomationRuleStatus;
use App\Enums\TemplateStatus;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CommunicationTemplate;
use App\Models\Employee;
use App\Models\User;
use App\Services\Automation\AutomationRuleService;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\CommunicationTemplateService;
use App\Services\Communication\MessageContext;
use Database\Seeders\RolePermissionSeeder;

/**
 * Phase 8.6 automation and communication configuration (D8.6-020…023): lifecycle changes need a
 * reason; the author of a change needs someone else to activate it (CHRO/VP HR exempt); priority
 * and owner are versioned; automation history is never deleted; a template used by live rules
 * cannot be archived; provider templates are versioned and messages link to their version.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->rules = app(AutomationRuleService::class);
    $this->vp = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('vp_hr');
    $this->lead = Employee::factory()->create();
    $this->leadUser = User::factory()->create(['employee_id' => $this->lead->id])->assignRole('manager');
    $this->author = Employee::factory()->reportingTo($this->lead)->create();
    $this->authorUser = User::factory()->create(['employee_id' => $this->author->id])->assignRole('manager');
});

function governedRuleData(Employee $team, array $overrides = []): array
{
    return [
        'name' => 'Confirm interviews',
        'trigger' => 'interview.scheduled',
        'conditions' => ['match' => 'all', 'rules' => []],
        'actions' => [['type' => 'create_action', 'action_type' => 'confirm_interview', 'owner' => 'recruiter', 'priority' => 'high', 'title' => 'Confirm']],
        'timing' => ['mode' => 'immediate'],
        'scope_type' => 'team',
        'scope_id' => $team->id,
        ...$overrides,
    ];
}

test('the author of a rule change cannot activate it; another automation.activate holder can', function (): void {
    $rule = $this->rules->create(governedRuleData($this->author), $this->authorUser);

    expect(fn () => $this->rules->activate($rule, $this->authorUser, 'Looks right'))->toThrow(DomainException::class, 'someone else');

    $this->rules->activate($rule, $this->leadUser, 'Checked the conditions');

    expect($rule->fresh()->status)->toBe(AutomationRuleStatus::Active)
        ->and(AuditLog::query()->where('auditable_id', $rule->id)->where('auditable_type', AutomationRule::class)->where('action', 'automation_rule_activated')->sole()->reason)->toBe('Checked the conditions');
});

test('CHRO and VP HR may activate their own changes', function (): void {
    $rule = $this->rules->create(governedRuleData($this->author, ['scope_type' => 'organization', 'scope_id' => null]), $this->vp);

    $this->rules->activate($rule, $this->vp, 'Org-wide rule, reviewed');

    expect($rule->fresh()->status)->toBe(AutomationRuleStatus::Active);
});

test('edit, activate, pause and archive all need a reason, which is recorded', function (): void {
    $rule = $this->rules->create(governedRuleData($this->author, ['scope_type' => 'organization', 'scope_id' => null]), $this->vp);

    expect(fn () => $this->rules->update($rule, ['cooldown_minutes' => 30], $this->vp))->toThrow(DomainException::class, 'reason')
        ->and(fn () => $this->rules->activate($rule, $this->vp))->toThrow(DomainException::class, 'reason');

    $this->rules->update($rule, ['cooldown_minutes' => 30], $this->vp, 'Avoid duplicate tasks');
    $this->rules->activate($rule->fresh(), $this->vp, 'Ready');

    expect(fn () => $this->rules->pause($rule->fresh(), $this->vp, ' '))->toThrow(DomainException::class, 'reason')
        ->and($rule->versions()->orderByDesc('version')->first()->change_summary)->toBe('Avoid duplicate tasks');

    $this->rules->pause($rule->fresh(), $this->vp, 'Recruiting freeze');

    expect(AuditLog::query()->where('auditable_id', $rule->id)->where('action', 'automation_rule_paused')->sole()->reason)->toBe('Recruiting freeze');
});

test('priority and owner are part of the rule version', function (): void {
    $rule = $this->rules->create(governedRuleData($this->author, ['scope_type' => 'organization', 'scope_id' => null]), $this->vp);

    $this->rules->update($rule, ['priority' => 5], $this->vp, 'Run before other rules');

    expect($rule->fresh()->version)->toBe(2)
        ->and($rule->versions()->orderByDesc('version')->first()->snapshot)->toMatchArray(['priority' => 5, 'owner_id' => $this->vp->id]);
});

test('archiving audits the pending runs it cancels', function (): void {
    $rule = $this->rules->create(governedRuleData($this->author, ['scope_type' => 'organization', 'scope_id' => null]), $this->vp);
    $this->rules->activate($rule, $this->vp, 'Go');
    AutomationExecution::factory()->count(2)->create(['automation_rule_id' => $rule->id, 'automation_rule_version_id' => $rule->currentVersion()->first()?->id, 'status' => 'pending']);

    $this->rules->archive($rule->fresh(), $this->vp, 'Replaced by a new rule');

    $audit = AuditLog::query()->where('auditable_id', $rule->id)->where('action', 'automation_rule_pending_cancelled')->sole();

    expect($audit->changes)->toBe(['pending_runs_cancelled' => 2, 'escalations_cancelled' => 0])
        ->and($audit->reason)->toBe('Replaced by a new rule');
});

test('automation history rows cannot be deleted one by one', function (): void {
    $rule = $this->rules->create(governedRuleData($this->author, ['scope_type' => 'organization', 'scope_id' => null]), $this->vp);
    $execution = AutomationExecution::factory()->create(['automation_rule_id' => $rule->id]);

    expect(fn () => $rule->versions()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => $execution->delete())->toThrow(LogicException::class);
});

test('a template sent by a live automation rule cannot be archived', function (): void {
    $template = CommunicationTemplate::factory()->create(['key' => 'candidate_checkin']);
    AutomationRule::factory()->active()->withActions([['type' => 'send_communication', 'template_key' => 'candidate_checkin', 'channels' => ['email']]])->create(['name' => 'Weekly check-in']);
    $service = app(CommunicationTemplateService::class);

    expect(fn () => $service->update($template, ['status' => TemplateStatus::Archived->value]))->toThrow(DomainException::class, 'Weekly check-in')
        ->and(fn () => $service->setStatus($template, TemplateStatus::Archived))->toThrow(DomainException::class, 'Weekly check-in')
        ->and($template->fresh()->status)->toBe(TemplateStatus::Active);

    $unused = CommunicationTemplate::factory()->create(['key' => 'unused_key']);
    $service->setStatus($unused, TemplateStatus::Archived);

    expect($unused->fresh()->status)->toBe(TemplateStatus::Archived)
        ->and($service->builtInArchiveWarning(CommunicationTemplate::factory()->make(['key' => 'interview_scheduled'])))->toContain('sent automatically');
});

test('a provider template change is a new version and a message links to the version it used', function (): void {
    $service = app(CommunicationTemplateService::class);
    $template = $service->create(['key' => 'nudge', 'name' => 'Nudge', 'channel' => 'email', 'subject' => 'Hi', 'body' => 'Hello {{candidate.name}}', 'status' => TemplateStatus::Active->value]);
    $service->update($template, ['provider_template' => 'nudge_v2']);

    $versions = $template->fresh()->versions()->reorder('version')->get();
    $application = CandidateApplication::factory()->create();
    app(CommunicationService::class)->sendAutomatic('nudge', new MessageContext($application->candidate, $application), 'nudge:1');

    expect($versions->pluck('version')->all())->toBe([1, 2])
        ->and($versions[1]->provider_template)->toBe('nudge_v2')
        ->and(CandidateCommunication::query()->where('communication_template_id', $template->id)->value('communication_template_version_id'))->toBe($versions[1]->id);
});
