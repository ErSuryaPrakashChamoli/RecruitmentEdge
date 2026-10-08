<?php

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\PreferenceStatus;
use App\Filament\Pages\Integrations;
use App\Filament\Resources\CandidateCommunications\CandidateCommunicationResource;
use App\Filament\Resources\CandidateCommunications\Pages\ListCandidateCommunications;
use App\Filament\Resources\Candidates\Pages\ViewCandidate;
use App\Filament\Resources\CommunicationTemplates\CommunicationTemplateResource;
use App\Filament\Resources\CommunicationTemplates\Pages\CreateCommunicationTemplate;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CandidateCommunicationPreference;
use App\Models\CommunicationTemplate;
use App\Models\Employee;
use App\Models\IntegrationStatus;
use App\Models\User;
use App\Services\AI\Tools\ActionTools\SendCandidateEmailTool;
use App\Services\Communication\CommunicationPreferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config(['mail.default' => 'array', 'mail.from.address' => 'hiring@example.com']);
});

function commUser(string $role): User
{
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole($role);
    actingAs($user);

    return $user;
}

test('a recruiter sends a custom email from the Candidate 360 page', function (): void {
    Queue::fake();
    $user = commUser('recruiter');
    $candidate = CandidateApplication::factory()->create(['recruiter_id' => $user->employee_id])->candidate;
    $candidate->update(['email' => 'cand@example.com']);

    Livewire::test(ViewCandidate::class, ['record' => $candidate->id])
        ->callAction('sendMessage', ['channel' => 'email', 'subject' => 'Hello {{candidate.first_name}}', 'body' => 'Checking in.'])
        ->assertNotified('Message queued for sending');

    $message = CandidateCommunication::query()->sole();

    expect($message->subject)->toBe('Hello '.strtok($candidate->full_name, ' '))
        ->and($message->sent_by)->toBe($user->employee_id)
        ->and($message->status)->toBe(CommunicationStatus::Queued);
});

test('sending to an opted-out candidate from the form is refused and the block recorded', function (): void {
    Queue::fake();
    commUser('chro');
    $candidate = Candidate::factory()->create(['email' => 'x@example.com']);
    app(CommunicationPreferenceService::class)->set($candidate, CommunicationChannel::Email, PreferenceStatus::OptedOut, 'recruiter');

    Livewire::test(ViewCandidate::class, ['record' => $candidate->id])
        ->callAction('sendMessage', ['channel' => 'email', 'subject' => 'Hi', 'body' => 'Hi'])
        ->assertNotified('Message not sent');

    expect(CandidateCommunication::query()->sole()->status)->toBe(CommunicationStatus::Blocked);
    Queue::assertNothingPushed();
});

test('recruiters without communications.send cannot send', function (): void {
    commUser('employee');

    expect(auth()->user()->can('create', CandidateCommunication::class))->toBeFalse();
    $this->get(CandidateCommunicationResource::getUrl('index'))->assertForbidden();
});

test('the Communication Center only lists messages to candidates in the user\'s hierarchy', function (): void {
    $user = commUser('recruiter');
    $mine = CandidateCommunication::factory()->create(['candidate_id' => CandidateApplication::factory()->create(['recruiter_id' => $user->employee_id])->candidate_id]);
    $theirs = CandidateCommunication::factory()->create();

    Livewire::test(ListCandidateCommunications::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);
    $this->get(CandidateCommunicationResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
});

test('a template with an unknown variable cannot be saved', function (): void {
    commUser('chro');

    Livewire::test(CreateCommunicationTemplate::class)
        ->fillForm(['name' => 'Bad', 'key' => 'bad', 'channel' => 'email', 'language' => 'en', 'status' => 'active', 'subject' => 'Hi', 'body' => 'Your salary {{candidate.current_salary}}'])
        ->call('create')
        ->assertNotified('Template could not be saved');

    expect(CommunicationTemplate::query()->count())->toBe(0);
});

test('template management needs communications.templates', function (): void {
    commUser('recruiter');

    $this->get(CommunicationTemplateResource::getUrl('index'))->assertForbidden();
});

test('a recruiter records a preference change with a reason, which is audited', function (): void {
    commUser('chro');
    $candidate = Candidate::factory()->create();

    Livewire::test(ViewCandidate::class, ['record' => $candidate->id])
        ->callAction('editPreferences', ['email' => 'allowed', 'whatsapp' => 'allowed', 'sms' => 'opted_out', 'phone' => 'unknown', 'reason' => 'Asked on the phone'])
        ->assertNotified('Communication preferences updated');

    expect(app(CommunicationPreferenceService::class)->statusFor($candidate, CommunicationChannel::WhatsApp))->toBe(PreferenceStatus::Allowed)
        ->and(app(CommunicationPreferenceService::class)->statusFor($candidate, CommunicationChannel::Sms))->toBe(PreferenceStatus::OptedOut)
        ->and(AuditLog::query()->where('auditable_type', CandidateCommunicationPreference::class)->count())->toBe(3);
});

test('the integrations page reports the log mailer as not operational after a test', function (): void {
    config(['mail.default' => 'log']);
    commUser('chro');

    Livewire::test(Integrations::class)
        ->assertSee('WhatsApp Business Cloud API')
        ->callAction('testIntegration', arguments: ['key' => 'mail'])
        ->assertNotified('Connection test failed');

    $status = IntegrationStatus::query()->where('provider', 'mail')->sole();

    expect($status->last_test_ok)->toBeFalse()
        ->and($status->last_test_message)->toContain('does not deliver email outside this server')
        ->and(AuditLog::query()->where('action', 'integration_tested')->exists())->toBeTrue();
});

test('only integrations.manage users can open the integrations page', function (): void {
    commUser('manager');

    $this->get(Integrations::getUrl())->assertForbidden();
});

test('the AI send email tool respects opt-outs through the Communication Center', function (): void {
    $user = commUser('chro');
    $candidate = Candidate::factory()->create(['email' => 'ai@example.com']);
    app(CommunicationPreferenceService::class)->set($candidate, CommunicationChannel::Email, PreferenceStatus::OptedOut, 'recruiter');

    $result = app(SendCandidateEmailTool::class)->handle(['candidate_id' => $candidate->id, 'subject' => 'Hi', 'body' => 'Hello'], $user);

    expect($result->success)->toBeFalse()->and($result->error)->toContain('opted out')
        ->and(CandidateCommunication::query()->sole()->trigger->value)->toBe('ai');
});
