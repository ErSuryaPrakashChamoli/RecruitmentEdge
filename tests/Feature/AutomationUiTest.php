<?php

use App\Enums\ActionPriority;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationRuleStatus;
use App\Enums\CandidateStage;
use App\Enums\RecruiterActionStatus;
use App\Filament\Pages\AutomationDashboard;
use App\Filament\Pages\AutomationTemplates;
use App\Filament\Pages\NotificationCenter;
use App\Filament\Resources\AutomationEscalations\Pages\ListAutomationEscalations;
use App\Filament\Resources\AutomationExecutions\AutomationExecutionResource;
use App\Filament\Resources\AutomationExecutions\Pages\ListAutomationExecutions;
use App\Filament\Resources\AutomationExecutions\Pages\ViewAutomationExecution;
use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use App\Filament\Resources\AutomationRules\Pages\CreateAutomationRule;
use App\Filament\Resources\AutomationRules\Pages\DryRunAutomationRule;
use App\Filament\Resources\AutomationRules\Pages\EditAutomationRule;
use App\Filament\Resources\AutomationRules\Pages\ListAutomationRules;
use App\Filament\Resources\AutomationRules\Pages\ViewAutomationRule;
use App\Filament\Resources\RecruiterActions\Pages\ListRecruiterActions;
use App\Filament\Resources\RecruiterActions\Widgets\SuggestedNextActions;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\RecruiterAction;
use App\Models\User;
use App\Services\InterviewService;
use App\Services\NotificationDispatchService;
use App\Services\RecruiterActionService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function automationUiUser(string $role, ?Employee $employee = null): User
{
    $user = User::factory()->create(['employee_id' => ($employee ?? Employee::factory()->create())->id])->assignRole($role);
    actingAs($user);

    return $user;
}

test('automation pages render for an administrator', function (): void {
    automationUiUser('vp_hr');
    $rule = AutomationRule::factory()->create();

    foreach ([AutomationDashboard::getUrl(), AutomationTemplates::getUrl(), AutomationRuleResource::getUrl('index'), AutomationRuleResource::getUrl('create'), AutomationRuleResource::getUrl('view', ['record' => $rule]), AutomationRuleResource::getUrl('edit', ['record' => $rule]), AutomationRuleResource::getUrl('dry-run', ['record' => $rule]), NotificationCenter::getUrl(), ListRecruiterActions::getUrl(), ListAutomationExecutions::getUrl(), ListAutomationEscalations::getUrl()] as $url) {
        get($url)->assertOk();
    }
});

test('recruiters cannot reach automation administration but have an Action Center and notifications', function (): void {
    automationUiUser('recruiter');
    $rule = AutomationRule::factory()->create();

    get(AutomationRuleResource::getUrl('index'))->assertForbidden();
    get(AutomationRuleResource::getUrl('edit', ['record' => $rule]))->assertForbidden();
    get(AutomationDashboard::getUrl())->assertForbidden();
    get(ListAutomationExecutions::getUrl())->assertForbidden();
    get(ListRecruiterActions::getUrl())->assertOk();
    get(NotificationCenter::getUrl())->assertOk();
});

test('the visual builder creates a versioned draft rule', function (): void {
    automationUiUser('vp_hr');

    Livewire::test(CreateAutomationRule::class)
        ->fillForm([
            'name' => 'Offer chaser',
            'scope_type' => 'organization',
            'trigger' => 'offer.released',
            'timing' => ['mode' => 'delay', 'amount' => 3, 'unit' => 'days', 'direction' => 'after', 'anchor' => 'event'],
            'condition_match' => 'all',
            'condition_rules' => [['field' => 'offer.status', 'operator' => 'equals', 'choice' => 'released']],
            'actions_builder' => [['type' => 'create_action', 'data' => ['action_type' => 'chase_offer_decision', 'owner' => 'recruiter', 'priority' => 'high', 'title' => 'Chase {{candidate.name}}', 'due_in_hours' => 8]]],
            'escalation_steps' => [['after' => 1, 'unit' => 'days', 'target' => 'reports_to', 'priority' => 'high']],
            'stop_match' => 'any',
            'stop_rules' => [['field' => 'offer.status', 'operator' => 'in', 'values' => ['accepted', 'rejected']]],
            'failure_behavior' => 'continue',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AutomationRule::query()->sole();

    expect($rule->status)->toBe(AutomationRuleStatus::Draft)
        ->and($rule->version)->toBe(1)
        ->and($rule->conditions['rules'][0])->toBe(['field' => 'offer.status', 'operator' => 'equals', 'value' => 'released'])
        ->and($rule->actions[0]['type'])->toBe('create_action')
        ->and($rule->escalation['stop_conditions']['rules'][0]['value'])->toBe(['accepted', 'rejected']);
});

test('builder validation problems are shown instead of saving', function (): void {
    automationUiUser('vp_hr');

    Livewire::test(CreateAutomationRule::class)
        ->fillForm([
            'name' => 'Bad rule',
            'scope_type' => 'organization',
            'trigger' => 'offer.released',
            'timing' => ['mode' => 'delay', 'amount' => 2, 'unit' => 'hours', 'direction' => 'before', 'anchor' => 'event'],
            'actions_builder' => [['type' => 'add_audit_event', 'data' => ['note' => 'x']]],
            'failure_behavior' => 'continue',
        ])
        ->call('create')
        ->assertNotified('The rule could not be saved');

    expect(AutomationRule::query()->count())->toBe(0);
});

test('editing through the builder round-trips and versions the rule', function (): void {
    automationUiUser('vp_hr');
    $rule = AutomationRule::factory()->create(['conditions' => ['match' => 'any', 'rules' => [
        ['field' => 'interview.confirmed', 'operator' => 'equals', 'value' => '0'],
        ['match' => 'all', 'negate' => true, 'rules' => [['field' => 'interview.scheduled_at', 'operator' => 'within', 'amount' => 2, 'unit' => 'hours']]],
    ]]]);

    Livewire::test(EditAutomationRule::class, ['record' => $rule->id])
        ->assertFormSet(['condition_match' => 'any'])
        ->fillForm(['name' => 'Renamed', 'cooldown_minutes' => 30, 'change_reason' => 'Clearer name, fewer repeats'])
        ->call('save')
        ->assertHasNoFormErrors();

    $rule->refresh();

    expect($rule->name)->toBe('Renamed')
        ->and($rule->version)->toBe(2)
        ->and($rule->conditions['rules'][1])->toMatchArray(['match' => 'all', 'negate' => true])
        ->and($rule->conditions['rules'][1]['rules'][0])->toMatchArray(['operator' => 'within', 'amount' => 2, 'unit' => 'hours']);
});

test('activate and pause from the rule page', function (): void {
    automationUiUser('vp_hr');
    $rule = AutomationRule::factory()->create();

    Livewire::test(ViewAutomationRule::class, ['record' => $rule->id])->callAction('activate', ['reason' => 'Reviewed'])->assertNotified('Rule activated');
    expect($rule->fresh()->status)->toBe(AutomationRuleStatus::Active);

    Livewire::test(ViewAutomationRule::class, ['record' => $rule->id])->callAction('pause', ['reason' => 'Holiday period'])->assertNotified('Rule paused');
    expect($rule->fresh()->status)->toBe(AutomationRuleStatus::Paused);
});

test('a manager cannot activate an organization-wide rule from the list', function (): void {
    automationUiUser('manager');
    $rule = AutomationRule::factory()->create();

    Livewire::test(ListAutomationRules::class)->assertActionHidden(TestAction::make('activate')->table($rule));
});

test('the dry run explains conditions and actions without doing anything', function (): void {
    automationUiUser('chro');
    $recruiter = Employee::factory()->create();
    User::factory()->create(['employee_id' => $recruiter->id]);
    $interview = Interview::factory()->create(['candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id, 'current_stage' => CandidateStage::InterviewScheduled])->id]);
    $rule = AutomationRule::factory()->create(['conditions' => ['match' => 'all', 'rules' => [['field' => 'interview.confirmed', 'operator' => 'equals', 'value' => '0']]]]);

    Livewire::test(DryRunAutomationRule::class, ['record' => $rule->id])
        ->set('subjectId', (string) $interview->id)
        ->call('run')
        ->assertSee('Would run')
        ->assertSee('Interview: Is confirmed')
        ->assertSee('Create "Confirm interview" action for Recruiter');

    expect(RecruiterAction::query()->count())->toBe(0)
        ->and(AutomationExecution::query()->count())->toBe(0);
});

test('failed runs are listed under failures and can be retried', function (): void {
    automationUiUser('chro');
    $failed = AutomationExecution::factory()->failed('Provider down')->create();
    AutomationExecution::factory()->create(['status' => AutomationExecutionStatus::Completed]);

    Livewire::test(ListAutomationExecutions::class, ['activeTab' => 'failed'])
        ->assertCanSeeTableRecords([$failed])
        ->assertCountTableRecords(1);

    Livewire::test(ViewAutomationExecution::class, ['record' => $failed->id])
        ->assertSee('Provider down')
        ->callAction('retry');

    expect($failed->fresh()->retry_count)->toBe(1);
});

test('executions are hierarchy-scoped', function (): void {
    $manager = Employee::factory()->create();
    automationUiUser('manager', $manager);
    $mine = AutomationExecution::factory()->create(['recruiter_id' => Employee::factory()->reportingTo($manager)->create()->id]);
    $other = AutomationExecution::factory()->create(['recruiter_id' => Employee::factory()->create()->id]);

    Livewire::test(ListAutomationExecutions::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);
    get(ViewAutomationExecution::getUrl(['record' => $other]))->assertNotFound();
});

test('the Action Center lets an owner complete work and shows overdue items', function (): void {
    $user = automationUiUser('recruiter');
    $overdue = RecruiterAction::factory()->overdue()->priority(ActionPriority::Critical)->create(['owner_id' => $user->employee_id]);
    $later = RecruiterAction::factory()->create(['owner_id' => $user->employee_id]);
    $someoneElse = RecruiterAction::factory()->create();

    Livewire::test(ListRecruiterActions::class, ['activeTab' => 'overdue'])
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$later, $someoneElse])
        ->callAction(TestAction::make('complete')->table($overdue), ['note' => 'Called'])
        ->assertNotified('Action completed');

    expect($overdue->fresh()->status)->toBe(RecruiterActionStatus::Completed);
});

test('a manager assigns an action to a team member', function (): void {
    $manager = Employee::factory()->create();
    automationUiUser('manager', $manager);
    $member = Employee::factory()->reportingTo($manager)->create();
    User::factory()->create(['employee_id' => $member->id]);

    Livewire::test(ListRecruiterActions::class)
        ->callAction('assign', ['title' => 'Source 5 candidates', 'owner_id' => $member->id, 'action_type' => 'source_candidates', 'priority' => 'high'])
        ->assertNotified('Action assigned');

    expect(RecruiterAction::query()->sole()->owner_id)->toBe($member->id);
});

test('the notification center shows priority, marks read, and only dismisses critical items once read', function (): void {
    $user = automationUiUser('recruiter');
    $notifications = app(NotificationDispatchService::class);
    $notifications->alert($user, 'Automation', 'Urgent thing', 'Body', 'danger', null, 'n-1', ActionPriority::Critical, ['rule_id' => 9]);
    $notifications->alert($user, 'Recruitment', 'FYI', 'Body', 'info', null, 'n-2');
    [$critical, $info] = [$user->notifications()->where('data->viewData->dedupeKey', 'n-1')->sole(), $user->notifications()->where('data->viewData->dedupeKey', 'n-2')->sole()];

    Livewire::test(NotificationCenter::class)
        ->assertSee('Critical')
        ->assertSee('Informational')
        ->assertActionHidden(TestAction::make('dismiss')->table($critical))
        ->callAction(TestAction::make('dismiss')->table($info))
        ->callAction(TestAction::make('toggleRead')->table($critical))
        ->assertActionVisible(TestAction::make('dismiss')->table($critical))
        ->filterTable('priority', 'critical')
        ->assertCanSeeTableRecords([$critical])
        ->filterTable('source', 'system')
        ->assertCanNotSeeTableRecords([$critical]);

    expect($user->notifications()->count())->toBe(1)
        ->and($critical->fresh()->read_at)->not->toBeNull();
});

test('using a template from the templates page creates a draft and opens it', function (): void {
    automationUiUser('vp_hr');

    Livewire::test(AutomationTemplates::class)->call('useTemplate', 'offer_pending_escalation')->assertRedirect();

    expect(AutomationRule::query()->sole())->template_key->toBe('offer_pending_escalation')->status->toBe(AutomationRuleStatus::Draft);
});

test('an action center suggestion can be turned into an owned action', function (): void {
    $user = automationUiUser('recruiter');
    CandidateApplication::factory()->create(['recruiter_id' => $user->employee_id, 'current_stage' => CandidateStage::Selected, 'last_activity_at' => now()->subDays(9)]);

    Livewire::test(SuggestedNextActions::class)
        ->assertSee('Re-engage the candidate')
        ->call('createAction', 0)
        ->call('createAction', 0);

    expect(RecruiterAction::query()->sole()->owner_id)->toBe($user->employee_id)
        ->and(app(RecruiterActionService::class)->visibleTo($user)->count())->toBe(1);
});

test('a completed run shows its condition trace, action results and escalations', function (): void {
    automationUiUser('chro');
    $manager = Employee::factory()->create();
    User::factory()->create(['employee_id' => $manager->id])->assignRole('manager');
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    User::factory()->create(['employee_id' => $recruiter->id]);
    AutomationRule::factory()->active()->create([
        'conditions' => ['match' => 'all', 'rules' => [['field' => 'interview.confirmed', 'operator' => 'equals', 'value' => '0']]],
        'escalation' => ['steps' => [['after' => 0, 'unit' => 'hours', 'target' => 'reports_to']]],
    ]);
    Interviewer::factory()->create(['employee_id' => $manager->id]);
    app(InterviewService::class)->schedule(CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]), ['interviewer_id' => $manager->id, 'scheduled_at' => now()->addDay(), 'mode' => 'video_call']);
    $this->artisan('recruitment:automation:process');

    get(AutomationExecutionResource::getUrl('view', ['record' => AutomationExecution::query()->sole()]))
        ->assertOk()
        ->assertSee('Interview: Is confirmed')
        ->assertSee('✓ met')
        ->assertSee('Escalated to');
});
