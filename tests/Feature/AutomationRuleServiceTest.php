<?php

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationScope;
use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\TemplateStatus;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\User;
use App\Services\Automation\AutomationRuleService;
use App\Services\Automation\AutomationTemplateCatalog;
use App\Services\Communication\CommunicationTemplateService;
use App\Services\InterviewService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->rules = app(AutomationRuleService::class);
    $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('vp_hr');
    $this->managerEmployee = Employee::factory()->create();
    $this->manager = User::factory()->create(['employee_id' => $this->managerEmployee->id])->assignRole('manager');
});

/**
 * @return array<string, mixed>
 */
function ruleData(array $overrides = []): array
{
    return [
        'name' => 'Confirm upcoming interviews',
        'trigger' => 'interview.scheduled',
        'conditions' => ['match' => 'all', 'rules' => [['field' => 'interview.confirmed', 'operator' => 'equals', 'value' => '0']]],
        'actions' => [['type' => 'create_action', 'action_type' => 'confirm_interview', 'owner' => 'recruiter', 'priority' => 'high', 'title' => 'Confirm']],
        'timing' => ['mode' => 'immediate'],
        'scope_type' => 'organization',
        ...$overrides,
    ];
}

test('creating a rule stores a draft with its first immutable version', function (): void {
    $rule = $this->rules->create(ruleData(), $this->admin);

    expect($rule->status)->toBe(AutomationRuleStatus::Draft)
        ->and($rule->key)->toBe('confirm_upcoming_interviews')
        ->and($rule->version)->toBe(1)
        ->and($rule->versions()->sole()->snapshot['trigger'])->toBe('interview.scheduled')
        ->and(AuditLog::query()->where('auditable_type', AutomationRule::class)->where('action', 'created')->exists())->toBeTrue();
});

test('editing the configuration creates a new version; editing only the name does not', function (): void {
    $rule = $this->rules->create(ruleData(), $this->admin);

    $this->rules->update($rule, ['description' => 'Just words'], $this->admin);
    expect($rule->fresh()->version)->toBe(1);

    $this->rules->update($rule, ['cooldown_minutes' => 60], $this->admin);
    expect($rule->fresh()->version)->toBe(2)
        ->and($rule->versions()->orderBy('version')->pluck('snapshot')->map(fn ($s) => $s['cooldown_minutes'])->all())->toBe([null, 60]);
});

test('invalid configurations are rejected with readable reasons', function (array $overrides, string $message): void {
    expect(fn () => $this->rules->create(ruleData($overrides), $this->admin))->toThrow(DomainException::class, $message);
})->with([
    'unknown trigger' => [['trigger' => 'candidate.hacked'], 'Choose a valid trigger'],
    'unknown field' => [['conditions' => ['match' => 'all', 'rules' => [['field' => 'candidate.password', 'operator' => 'equals', 'value' => 'x']]]], 'choose a valid field'],
    'wrong operator for type' => [['conditions' => ['match' => 'all', 'rules' => [['field' => 'interview.scheduled_at', 'operator' => 'contains', 'value' => 'x']]]], 'choose a valid comparison'],
    'missing value' => [['conditions' => ['match' => 'all', 'rules' => [['field' => 'application.stage', 'operator' => 'equals']]]], 'enter a value'],
    'unknown action' => [['actions' => [['type' => 'run_sql', 'sql' => 'drop table users']]], 'choose a valid action type'],
    'hiring decision' => [['actions' => [['type' => 'move_stage', 'stage' => CandidateStage::Selected->value]]], 'Hiring decisions stay with people'],
    'duplicate phase 5 message' => [['actions' => [['type' => 'send_communication', 'template_key' => 'interview_scheduled']]], 'already sent automatically'],
    'unsafe template variable' => [['actions' => [['type' => 'notify', 'recipient' => 'recruiter', 'title' => 'Hi {{candidate.salary}}']]], 'Unknown template variable'],
    'delay before the event' => [['timing' => ['mode' => 'delay', 'amount' => 2, 'unit' => 'hours', 'direction' => 'before', 'anchor' => 'event']], 'measure it from a date'],
    'too deep' => [['conditions' => ['match' => 'all', 'rules' => [['match' => 'all', 'rules' => [['match' => 'all', 'rules' => [['match' => 'all', 'rules' => [['field' => 'application.stage', 'operator' => 'exists']]]]]]]]]], 'nested at most'],
    'bad escalation target' => [['escalation' => ['steps' => [['after' => 1, 'unit' => 'hours', 'target' => 'anyone']]]], 'choose who to escalate to'],
]);

test('only users with the organization permission can create or activate organization-wide rules', function (): void {
    expect(fn () => $this->rules->create(ruleData(), $this->manager))->toThrow(DomainException::class, 'automation.organization');

    $teamRule = $this->rules->create(ruleData(['scope_type' => AutomationScope::Team->value, 'scope_id' => $this->managerEmployee->id]), $this->manager);
    $this->rules->activate($teamRule, $this->manager);

    expect($teamRule->fresh()->status)->toBe(AutomationRuleStatus::Active);
});

test('a manager cannot scope a rule to someone outside their team', function (): void {
    $stranger = Employee::factory()->create();

    expect(fn () => $this->rules->create(ruleData(['scope_type' => AutomationScope::Recruiter->value, 'scope_id' => $stranger->id]), $this->manager))
        ->toThrow(DomainException::class, 'your own team');
});

test('users without the activate permission cannot activate', function (): void {
    $rule = $this->rules->create(ruleData(), $this->admin);
    $assistant = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('assistant_manager');

    expect(fn () => $this->rules->activate($rule, $assistant))->toThrow(DomainException::class, 'permission to activate');
});

test('activation requires an active template and a configured provider for chosen channels', function (): void {
    $rule = $this->rules->create(ruleData(['actions' => [['type' => 'send_communication', 'template_key' => 'candidate_checkin', 'channels' => ['whatsapp']]]]), $this->admin);

    expect(fn () => $this->rules->activate($rule, $this->admin))->toThrow(DomainException::class, 'no active "candidate_checkin" template');

    app(CommunicationTemplateService::class)->create(['key' => 'candidate_checkin', 'name' => 'Check-in', 'channel' => 'whatsapp', 'body' => 'Hi {{candidate.first_name}}', 'status' => TemplateStatus::Active, 'provider_template' => 'checkin']);

    expect(fn () => $this->rules->activate($rule, $this->admin))->toThrow(DomainException::class, 'provider is not configured');
});

test('an active rule cannot be edited into an invalid state, and archived rules cannot be edited', function (): void {
    $rule = $this->rules->create(ruleData(), $this->admin);
    $this->rules->activate($rule, $this->admin);

    expect(fn () => $this->rules->update($rule, ['actions' => []], $this->admin))->toThrow(DomainException::class, 'Add at least one action');

    $this->rules->archive($rule, $this->admin);

    expect(fn () => $this->rules->update($rule, ['name' => 'x'], $this->admin))->toThrow(DomainException::class, 'Archived');
});

test('lifecycle changes are audited and archiving cancels pending runs', function (): void {
    $rule = AutomationRule::factory()->active()->create(['timing' => ['mode' => 'delay', 'amount' => 2, 'unit' => 'hours', 'anchor' => 'event']]);
    $application = CandidateApplication::factory()->create();
    app(InterviewService::class)->schedule($application, ['interviewer_id' => Interviewer::factory()->create()->employee_id, 'scheduled_at' => now()->addDays(2), 'mode' => 'video_call']);

    $this->rules->pause($rule, $this->admin);
    $this->rules->activate($rule, $this->admin);
    $this->rules->archive($rule, $this->admin);

    expect(AuditLog::query()->whereIn('action', ['automation_rule_paused', 'automation_rule_activated', 'automation_rule_archived'])->count())->toBe(3)
        ->and(AutomationExecution::query()->sole()->status)->toBe(AutomationExecutionStatus::Cancelled);
});

test('duplicating a rule creates an independent draft', function (): void {
    $rule = AutomationRule::factory()->active()->create();

    $copy = $this->rules->duplicate($rule, $this->admin);

    expect($copy->status)->toBe(AutomationRuleStatus::Draft)
        ->and($copy->key)->not->toBe($rule->key)
        ->and($copy->version)->toBe(1)
        ->and($copy->actions)->toBe($rule->actions);
});

test('every catalogue template creates a valid draft rule', function (): void {
    foreach (app(AutomationTemplateCatalog::class)->all() as $template) {
        $rule = $this->rules->createFromTemplate($template['key'], $this->admin);

        expect($rule->status)->toBe(AutomationRuleStatus::Draft)
            ->and($rule->template_key)->toBe($template['key'])
            ->and($rule->scope_type)->toBe(AutomationScope::Organization);
    }

    expect(AutomationRule::query()->count())->toBe(10);
});

test('a manager\'s template-based rule is scoped to their team', function (): void {
    $rule = $this->rules->createFromTemplate('offer_pending_escalation', $this->manager);

    expect($rule->scope_type)->toBe(AutomationScope::Team)
        ->and($rule->scope_id)->toBe($this->managerEmployee->id);
});

test('an active template rule replaces its built-in alert so nobody is alerted twice', function (): void {
    $rule = $this->rules->createFromTemplate('interview_confirmation_reminder', $this->admin);
    $recruiter = Employee::factory()->create();
    User::factory()->create(['employee_id' => $recruiter->id]);
    Interview::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => InterviewStatus::Scheduled,
        'scheduled_at' => now()->addHours(20),
    ]);

    $this->artisan('notifications:dispatch-alerts');
    expect($recruiter->user->notifications()->where('data->title', 'like', '%not yet confirmed%')->count())->toBe(1);

    $this->rules->activate($rule, $this->admin);
    $recruiter->user->notifications()->delete();

    $this->artisan('notifications:dispatch-alerts')->expectsOutputToContain('Skipped unconfirmed_interviews');
    expect($recruiter->user->notifications()->where('data->title', 'like', '%not yet confirmed%')->count())->toBe(0)
        ->and($this->rules->supersededAlertChecks())->toBe(['unconfirmed_interviews']);
});
