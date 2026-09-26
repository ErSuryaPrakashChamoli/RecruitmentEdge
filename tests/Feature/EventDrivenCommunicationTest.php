<?php

use App\Enums\CandidateStage;
use App\Enums\CommunicationStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\SchedulingChannel;
use App\Enums\TemplateStatus;
use App\Events\CandidateAppliedOnline;
use App\Events\InterviewScheduled;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CandidateJoining;
use App\Models\CandidatePortalAccount;
use App\Models\CommunicationTemplate;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewAvailabilitySlot;
use App\Models\Offer;
use App\Models\User;
use App\Services\Communication\CommunicationTemplateService;
use App\Services\InterviewSchedulingService;
use App\Services\InterviewService;
use App\Services\OfferService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    config(['mail.default' => 'array', 'mail.from.address' => 'hiring@example.com']);
    Mail::fake();
    $templates = app(CommunicationTemplateService::class);

    foreach ([
        'interview_scheduled' => 'Interview on {{interview.date}} at {{interview.time}}',
        'interview_rescheduled' => 'Moved to {{interview.date}} at {{interview.time}}',
        'interview_cancelled' => 'Interview on {{interview.date}} cancelled',
        'interview_reminder' => 'Reminder: {{interview.time}}',
        'offer_released' => 'Offer for {{requisition.code}}',
        'joining_reminder' => 'See you on {{joining.date}}',
    ] as $key => $body) {
        $templates->create(['key' => $key, 'name' => $key, 'channel' => 'email', 'subject' => ucfirst(str_replace('_', ' ', $key)), 'body' => $body, 'status' => TemplateStatus::Active]);
    }
});

function eventApplication(): CandidateApplication
{
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Shortlisted]);
    $application->candidate->update(['email' => 'cand'.$application->id.'@example.com']);

    return $application->fresh();
}

test('scheduling an interview sends exactly one confirmation, even if the event is replayed', function (): void {
    $interview = app(InterviewService::class)->schedule(eventApplication(), ['interviewer_id' => Employee::factory()->create()->id, 'scheduled_at' => now()->addDays(3), 'mode' => 'video_call']);

    InterviewScheduled::dispatch($interview);

    $messages = CandidateCommunication::query()->where('interview_id', $interview->id)->get();

    expect($messages)->toHaveCount(1)
        ->and($messages->first()->idempotency_key)->toBe("interview.scheduled:{$interview->id}:email")
        ->and($messages->first()->status)->toBe(CommunicationStatus::Sent)
        ->and($messages->first()->trigger->value)->toBe('event');
    Mail::assertSentCount(1);
});

test('a self-scheduled booking sends one confirmation through the same path', function (): void {
    $application = eventApplication();

    app(InterviewSchedulingService::class)->book(app(InterviewSchedulingService::class)->invite($application), InterviewAvailabilitySlot::factory()->create(), SchedulingChannel::SignedLink);

    expect(CandidateCommunication::query()->where('candidate_id', $application->candidate_id)->pluck('idempotency_key')->all())
        ->toHaveCount(1)
        ->each->toStartWith('interview.scheduled:');
});

test('each reschedule to a new time sends a new notice, and a cancellation sends one', function (): void {
    $service = app(InterviewService::class);
    $interview = $service->schedule(eventApplication(), ['interviewer_id' => Employee::factory()->create()->id, 'scheduled_at' => now()->addDays(3), 'mode' => 'video_call']);

    $service->reschedule($interview, now()->addDays(4));
    $service->reschedule($interview->fresh(), now()->addDays(5));
    $service->cancel($interview->fresh(), 'Position on hold');

    $keys = CandidateCommunication::query()->where('interview_id', $interview->id)->pluck('idempotency_key');

    expect($keys->filter(fn ($k) => str_starts_with($k, 'interview.rescheduled:'))->count())->toBe(2)
        ->and($keys->filter(fn ($k) => str_starts_with($k, 'interview.cancelled:'))->count())->toBe(1);
});

test('releasing an offer notifies the candidate', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $releaser = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $releaser->assignRole('chro');
    actingAs($releaser);
    $application = eventApplication();
    $offer = Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Initiated]);

    app(OfferService::class)->moveTo($offer, OfferStatus::Released, $releaser->employee);

    expect(CandidateCommunication::query()->where('idempotency_key', "offer.released:{$offer->id}:email")->sole()->body)->toBe('Offer for '.$application->requisition->code);
});

test('without an active template nothing is sent automatically', function (): void {
    CommunicationTemplate::query()->update(['status' => TemplateStatus::Draft]);

    app(InterviewService::class)->schedule(eventApplication(), ['interviewer_id' => Employee::factory()->create()->id, 'scheduled_at' => now()->addDays(3), 'mode' => 'phone']);

    expect(CandidateCommunication::query()->count())->toBe(0);
});

test('reminders are sent once per interview time and per joining date', function (): void {
    $interview = Interview::factory()->create(['candidate_application_id' => eventApplication()->id, 'status' => InterviewStatus::Confirmed, 'scheduled_at' => now()->addHours(20)]);
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => eventApplication()->id, 'status' => JoiningStatus::Confirmed, 'expected_doj' => today()->addDays(2)]);

    $this->artisan('communications:send-reminders')->expectsOutputToContain('Queued 2 candidate reminder(s)')->assertSuccessful();
    $this->artisan('communications:send-reminders')->expectsOutputToContain('Queued 2 candidate reminder(s)')->assertSuccessful();

    expect(CandidateCommunication::query()->where('idempotency_key', "interview.reminder:{$interview->id}:{$interview->scheduled_at->timestamp}:email")->count())->toBe(1)
        ->and(CandidateCommunication::query()->where('idempotency_key', 'like', "joining.reminder:{$joining->id}:%")->count())->toBe(1);
    Mail::assertSentCount(2);
});

test('the candidate portal lists messages actually sent, never blocked ones', function (): void {
    $application = eventApplication();
    $account = CandidatePortalAccount::factory()->create(['candidate_id' => $application->candidate_id]);
    $sent = CandidateCommunication::factory()->create(['candidate_id' => $application->candidate_id, 'subject' => 'Your interview details']);
    $sent->forceFill(['status' => CommunicationStatus::Sent, 'sent_at' => now()])->save();
    $blocked = CandidateCommunication::factory()->create(['candidate_id' => $application->candidate_id, 'subject' => 'Internal blocked note']);
    $blocked->forceFill(['status' => CommunicationStatus::Blocked])->save();
    actingAs($account, 'candidate');

    $this->get(route('portal.dashboard'))->assertOk()->assertSee('Your interview details')->assertDontSee('Internal blocked note');
});

test('an online application sends the candidate an acknowledgement and alerts the recruiter', function (): void {
    app(CommunicationTemplateService::class)->create(['key' => 'application_received', 'name' => 'Received', 'channel' => 'email', 'subject' => 'Thanks', 'body' => 'We received {{application.reference}}', 'status' => TemplateStatus::Active]);
    $recruiter = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $application = eventApplication();
    lifecycleFixture(fn () => $application->forceFill(['recruiter_id' => $recruiter->employee_id])->save());

    CandidateAppliedOnline::dispatch($application->fresh());

    expect(CandidateCommunication::query()->where('idempotency_key', "application.received:{$application->id}:email")->sole()->body)->toBe("We received {$application->application_code}")
        ->and($recruiter->notifications()->sole()->data['title'])->toBe('[Recruitment] New online application');
});
