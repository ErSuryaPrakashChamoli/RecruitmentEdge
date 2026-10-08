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
use Illuminate\Support\Facades\DB;

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

    $this->rules->update($rule, ['cooldown_minutes' => 60], $this->admin, 'Throttle repeat runs');
    expect($rule->fresh()->version)->toBe(2)
        ->and($rule->versions()->orderBy('version')->pluck('snapshot')->map(fn ($s) => $s['cooldown_minutes'])->all())->toBe([null, 60]);
});

/**
 * The same value with every JSON object's keys in reverse order (lists keep their order) — what a
 * database that stores JSON in its own key order (MySQL) hands back.
 */
function rc01ReversedKeys(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $value = array_map(rc01ReversedKeys(...), $value);

    return array_is_list($value) ? $value : array_reverse($value, preserve_keys: true);
}

test('a configuration stored with its JSON keys in another order is the same configuration (P810-RC-01)', function (): void {
    $rule = $this->rules->create(ruleData([
        'actions' => [
            ['type' => 'create_action', 'action_type' => 'confirm_interview', 'owner' => 'recruiter', 'priority' => 'high', 'title' => 'Confirm'],
            ['type' => 'create_action', 'action_type' => 'confirm_interview', 'owner' => 'recruiter', 'priority' => 'low', 'title' => 'Remind'],
        ],
    ]), $this->admin);
    $stored = AutomationRule::query()->find($rule->id);

    $rewritten = DB::table('automation_rules')->where('id', $rule->id)->update(collect(['conditions', 'actions', 'timing'])
        ->mapWithKeys(fn (string $column) => [$column => json_encode(rc01ReversedKeys($stored->{$column}))])
        ->put('updated_at', now()->addMinute())->all());

    // One row was rewritten (MySQL counts changed rows and normalises the JSON itself, so the
    // timestamp makes the count meaningful on both databases).
    expect($rewritten)->toBe(1);

    // The in-memory model still holds the keys in the order they were written.
    $this->rules->update($rule, ['description' => 'Just words'], $this->admin);

    expect($rule->fresh()->version)->toBe(1)
        ->and($rule->versions()->count())->toBe(1);

    // A real change inside a nested object, or a different order of actions, is still a change.
    expect(fn () => $this->rules->update($rule->fresh(), ['actions' => [...array_slice($stored->actions, 0, 1), [...$stored->actions[1], 'priority' => 'high']]], $this->admin))
        ->toThrow(DomainException::class, 'A reason is required')
        ->and(fn () => $this->rules->update($rule->fresh(), ['actions' => array_reverse($stored->actions)], $this->admin))
        ->toThrow(DomainException::class, 'A reason is required');

    $this->rules->update($rule->fresh(), ['actions' => array_reverse($stored->actions)], $this->admin, 'Remind first');

    expect($rule->fresh()->version)->toBe(2)
        ->and($rule->versions()->orderByDesc('version')->first()->snapshot['actions'][0]['title'])->toBe('Remind');
});

test('configurations compare by value: object key order never matters, list order and value types do (P810-RC-01)', function (array $a, array $b, bool $same): void {
    expect(AutomationRuleService::sameConfiguration($a, $b))->toBe($same)
        ->and(AutomationRuleService::sameConfiguration($b, $a))->toBe($same);
})->with([
    'same JSON, same order' => [['a' => 1, 'b' => ['c' => 2]], ['a' => 1, 'b' => ['c' => 2]], true],
    'same JSON, object keys reordered' => [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], true],
    'nested object keys reordered' => [['x' => ['rules' => [['field' => 'f', 'operator' => 'equals', 'value' => '0']]]], ['x' => ['rules' => [['value' => '0', 'field' => 'f', 'operator' => 'equals']]]], true],
    'a different value' => [['a' => 1, 'b' => ['c' => 2]], ['a' => 1, 'b' => ['c' => 3]], false],
    'an added key' => [['a' => 1], ['a' => 1, 'b' => null], false],
    'a removed nested key' => [['a' => ['b' => 1, 'c' => 2]], ['a' => ['b' => 1]], false],
    'a list in another order' => [['steps' => [['n' => 1], ['n' => 2]]], ['steps' => [['n' => 2], ['n' => 1]]], false],
    'integer and string' => [['a' => 1], ['a' => '1'], false],
    'null and false' => [['a' => null], ['a' => false], false],
    'true and 1' => [['a' => true], ['a' => 1], false],
    'zero and empty string' => [['a' => 0], ['a' => ''], false],
]);

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
    expect($teamRule->status)->toBe(AutomationRuleStatus::Draft);

    // Phase 8.6 (D8.6-020): a manager cannot activate their own change — their own manager (who also
    // holds automation.activate for that team) reviews and activates it.
    $junior = Employee::factory()->reportingTo($this->managerEmployee)->create();
    $juniorUser = User::factory()->create(['employee_id' => $junior->id])->assignRole('manager');
    $juniorRule = $this->rules->create(ruleData(['scope_type' => AutomationScope::Team->value, 'scope_id' => $junior->id]), $juniorUser);

    expect(fn () => $this->rules->activate($juniorRule, $juniorUser, 'Self review'))->toThrow(DomainException::class, 'someone else');

    $this->rules->activate($juniorRule, $this->manager, 'Reviewed and approved');

    expect($juniorRule->fresh()->status)->toBe(AutomationRuleStatus::Active);
});

test('a manager cannot scope a rule to someone outside their team', function (): void {
    $stranger = Employee::factory()->create();

    expect(fn () => $this->rules->create(ruleData(['scope_type' => AutomationScope::Recruiter->value, 'scope_id' => $stranger->id]), $this->manager))
        ->toThrow(DomainException::class, 'your own team');
});

test('users without the activate permission cannot activate', function (): void {
    $rule = $this->rules->create(ruleData(), $this->admin);
    $assistant = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('assistant_manager');

    expect(fn () => $this->rules->activate($rule, $assistant, 'Reviewed and approved'))->toThrow(DomainException::class, 'permission to activate');
});

test('activation requires an active template and a configured provider for chosen channels', function (): void {
    $rule = $this->rules->create(ruleData(['actions' => [['type' => 'send_communication', 'template_key' => 'candidate_checkin', 'channels' => ['whatsapp']]]]), $this->admin);

    expect(fn () => $this->rules->activate($rule, $this->admin, 'Reviewed and approved'))->toThrow(DomainException::class, 'no active "candidate_checkin" template');

    app(CommunicationTemplateService::class)->create(['key' => 'candidate_checkin', 'name' => 'Check-in', 'channel' => 'whatsapp', 'body' => 'Hi {{candidate.first_name}}', 'status' => TemplateStatus::Active, 'provider_template' => 'checkin']);

    expect(fn () => $this->rules->activate($rule, $this->admin, 'Reviewed and approved'))->toThrow(DomainException::class, 'provider is not configured');
});

test('an active rule cannot be edited into an invalid state, and archived rules cannot be edited', function (): void {
    $rule = $this->rules->create(ruleData(), $this->admin);
    $this->rules->activate($rule, $this->admin, 'Reviewed and approved');

    expect(fn () => $this->rules->update($rule, ['actions' => []], $this->admin))->toThrow(DomainException::class, 'Add at least one action');

    $this->rules->archive($rule, $this->admin, 'No longer needed');

    expect(fn () => $this->rules->update($rule, ['name' => 'x'], $this->admin))->toThrow(DomainException::class, 'Archived');
});

test('lifecycle changes are audited and archiving cancels pending runs', function (): void {
    $rule = AutomationRule::factory()->active()->create(['timing' => ['mode' => 'delay', 'amount' => 2, 'unit' => 'hours', 'anchor' => 'event']]);
    $application = CandidateApplication::factory()->create();
    app(InterviewService::class)->schedule($application, ['interviewer_id' => Interviewer::factory()->create()->employee_id, 'scheduled_at' => now()->addDays(2), 'mode' => 'video_call']);

    $this->rules->pause($rule, $this->admin, 'Paused for review');
    $this->rules->activate($rule, $this->admin, 'Reviewed and approved');
    $this->rules->archive($rule, $this->admin, 'No longer needed');

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

    $this->rules->activate($rule, $this->admin, 'Reviewed and approved');
    $recruiter->user->notifications()->delete();

    $this->artisan('notifications:dispatch-alerts')->expectsOutputToContain('Skipped unconfirmed_interviews');
    expect($recruiter->user->notifications()->where('data->title', 'like', '%not yet confirmed%')->count())->toBe(0)
        ->and($this->rules->supersededAlertChecks())->toBe(['unconfirmed_interviews']);
});
