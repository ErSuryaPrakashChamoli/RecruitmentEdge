<?php

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationScope;
use App\Enums\CandidateStage;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\CommunicationTrigger;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\PreferenceStatus;
use App\Enums\TemplateStatus;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruiterAction;
use App\Models\User;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Communication\CommunicationTemplateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config(['mail.default' => 'array', 'mail.from.address' => 'hiring@example.com']);
    Mail::fake();
    $this->recruiter = Employee::factory()->create();
    User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
});

function sweepInterview(Employee $recruiter, array $attributes = []): Interview
{
    return Interview::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => InterviewStatus::Scheduled,
        'scheduled_at' => now()->addHours(10),
        ...$attributes,
    ]);
}

function upcomingInterviewRule(array $attributes = []): AutomationRule
{
    return AutomationRule::factory()->active()->create(['trigger' => 'interview.upcoming', 'timing' => ['amount' => 24, 'unit' => 'hours'], ...$attributes]);
}

test('the dispatch sweep runs a time-based rule once per record, however often it runs', function (): void {
    upcomingInterviewRule();
    $soon = sweepInterview($this->recruiter);
    sweepInterview($this->recruiter, ['scheduled_at' => now()->addDays(3)]);
    sweepInterview($this->recruiter, ['status' => InterviewStatus::Cancelled]);

    $this->artisan('recruitment:automation:dispatch')->expectsOutputToContain('1 record(s) matched, 1 execution(s) queued')->assertSuccessful();
    $this->artisan('recruitment:automation:dispatch')->expectsOutputToContain('1 record(s) matched, 0 execution(s) queued')->assertSuccessful();

    expect(AutomationExecution::query()->sole())
        ->subject_id->toBe($soon->id)
        ->status->toBe(AutomationExecutionStatus::Completed)
        ->and(RecruiterAction::query()->count())->toBe(1);
});

test('rescheduling re-arms a time-based rule for the new interview time', function (): void {
    upcomingInterviewRule();
    $interview = sweepInterview($this->recruiter);

    $this->artisan('recruitment:automation:dispatch');
    $interview->update(['scheduled_at' => now()->addHours(20)]);
    $this->artisan('recruitment:automation:dispatch');

    expect(AutomationExecution::query()->count())->toBe(2);
});

test('a repeating rule runs again in the next period only', function (): void {
    AutomationRule::factory()->active()->create(['trigger' => 'application.stuck', 'timing' => ['amount' => 2, 'unit' => 'days', 'repeat_every_hours' => 24]]);
    CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'last_activity_at' => now()->subDays(3)]);

    $this->artisan('recruitment:automation:dispatch');
    $this->artisan('recruitment:automation:dispatch');
    $this->travel(25)->hours();
    $this->artisan('recruitment:automation:dispatch');

    expect(AutomationExecution::query()->count())->toBe(2);
});

test('dry-run, rule, entity and limit options bound what the sweep touches', function (): void {
    $rule = upcomingInterviewRule();
    $first = sweepInterview($this->recruiter);
    sweepInterview($this->recruiter);

    $this->artisan('recruitment:automation:dispatch', ['--dry-run' => true])->expectsOutputToContain('[dry run]')->assertSuccessful();
    expect(AutomationExecution::query()->count())->toBe(0);

    $this->artisan('recruitment:automation:dispatch', ['--rule' => $rule->key, '--entity' => $first->id]);
    expect(AutomationExecution::query()->pluck('subject_id')->all())->toBe([$first->id]);

    $this->artisan('recruitment:automation:dispatch', ['--limit' => 1]);
    expect(AutomationExecution::query()->count())->toBe(2);
});

test('the sweep applies the rule scope in the query', function (): void {
    upcomingInterviewRule(['scope_type' => AutomationScope::Recruiter, 'scope_id' => $this->recruiter->id]);
    sweepInterview($this->recruiter);
    sweepInterview(Employee::factory()->create());

    $this->artisan('recruitment:automation:dispatch');

    expect(AutomationExecution::query()->count())->toBe(1);
});

test('joining and selected-without-offer schedule triggers find the right records', function (): void {
    AutomationRule::factory()->active()->create(['trigger' => 'joining.upcoming', 'timing' => ['amount' => 2, 'unit' => 'days']]);
    AutomationRule::factory()->active()->create(['trigger' => 'application.selected_without_offer', 'timing' => ['amount' => 24, 'unit' => 'hours']]);

    CandidateJoining::factory()->create(['status' => JoiningStatus::Expected, 'expected_doj' => now()->addDay()]);
    CandidateJoining::factory()->create(['status' => JoiningStatus::Expected, 'expected_doj' => now()->addDays(10)]);
    CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected, 'last_activity_at' => now()->subDays(2), 'recruiter_id' => $this->recruiter->id]);
    CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected, 'last_activity_at' => now()->subHours(2), 'recruiter_id' => $this->recruiter->id]);

    $this->artisan('recruitment:automation:dispatch');

    expect(AutomationExecution::query()->pluck('trigger')->sort()->values()->all())->toBe(['application.selected_without_offer', 'joining.upcoming']);
});

test('a send-message action goes through the communication service and respects preferences and the daily cap', function (): void {
    config(['automation.max_candidate_messages_per_day' => 1]);
    app(CommunicationTemplateService::class)->create(['key' => 'candidate_checkin', 'name' => 'Check-in', 'channel' => 'email', 'subject' => 'Checking in', 'body' => 'Hi {{candidate.first_name}}', 'status' => TemplateStatus::Active]);
    AutomationRule::factory()->active()->create([
        'trigger' => 'application.stuck',
        'timing' => ['amount' => 2, 'unit' => 'days', 'repeat_every_hours' => 1],
        'actions' => [['type' => 'send_communication', 'template_key' => 'candidate_checkin', 'channels' => ['email']]],
    ]);
    $application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'last_activity_at' => now()->subDays(3)]);
    $application->candidate->update(['email' => 'stuck@example.com']);

    $this->artisan('recruitment:automation:dispatch');
    $this->travel(2)->hours();
    $this->artisan('recruitment:automation:dispatch');

    $message = CandidateCommunication::query()->sole();
    $second = AutomationExecution::query()->latest('id')->first();

    expect($message->trigger)->toBe(CommunicationTrigger::Automation)
        ->and($message->status)->toBe(CommunicationStatus::Sent)
        ->and($second->actionExecutions->sole()->summary)->toContain('limit 1');

    $this->travel(1)->day();
    app(CommunicationPreferenceService::class)->set($application->candidate, CommunicationChannel::Email, PreferenceStatus::OptedOut, 'test');
    $this->artisan('recruitment:automation:dispatch');

    expect(CandidateCommunication::query()->latest('id')->first()->status)->toBe(CommunicationStatus::Blocked);
});
