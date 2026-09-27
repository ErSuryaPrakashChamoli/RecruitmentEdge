<?php

use App\Enums\AiToolCallStatus;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\CommunicationTrigger;
use App\Enums\InterviewStatus;
use App\Enums\OfferStatus;
use App\Enums\PreferenceStatus;
use App\Enums\TemplateStatus;
use App\Events\CommunicationFailed;
use App\Filament\Resources\CandidateCommunications\Pages\ViewCandidateCommunication;
use App\Jobs\SendCommunicationJob;
use App\Models\AiToolCall;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CommunicationTemplate;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\User;
use App\Services\CandidateTimelineService;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\CommunicationTemplateService;
use App\Services\Communication\Data\WebhookStatusUpdate;
use App\Services\Communication\DeliveryStatusService;
use App\Services\Communication\MessageContext;
use App\Services\Communication\ProviderCircuitBreaker;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.7 (D8.7-007/008/019/024, DQ-87-04): a queued message is re-checked against the world it
 * describes before it is sent; a paused provider holds messages instead of failing them; lost and
 * interrupted work is recovered or surfaced; an operator can resend a failed message.
 */
beforeEach(function (): void {
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'hiring@example.com',
        'services.twilio' => ['sid' => 'AC1', 'token' => 'tok', 'from' => '+15550001111'],
    ]);
    Queue::fake();
    $this->communications = app(CommunicationService::class);
});

function deliveryTemplate(string $key, CommunicationChannel $channel = CommunicationChannel::Email, string $body = 'Hi {{candidate.first_name}}, see you on {{interview.date}}.'): CommunicationTemplate
{
    return app(CommunicationTemplateService::class)->create([
        'key' => $key,
        'name' => ucfirst(str_replace('_', ' ', $key)),
        'channel' => $channel,
        'subject' => $channel === CommunicationChannel::Email ? 'About your application' : null,
        'body' => $body,
        'status' => TemplateStatus::Active,
    ]);
}

function deliveryContext(array $application = []): MessageContext
{
    $candidate = Candidate::factory()->create(['full_name' => 'Asha Verma', 'email' => 'asha@example.com', 'mobile' => '9876543210']);
    $application = CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'current_stage' => CandidateStage::InterviewScheduled, ...$application]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Scheduled, 'scheduled_at' => now()->addDays(2)->setTime(10, 30)]);

    return MessageContext::forInterview($interview);
}

function deliverNow(CandidateCommunication $message): CandidateCommunication
{
    app()->call([new SendCommunicationJob($message->id), 'handle']);

    return $message->fresh();
}

function expectSuppressed(CandidateCommunication $message, string $reason): void
{
    expect($message->status)->toBe(CommunicationStatus::Blocked)
        ->and($message->blocked_reason)->toContain($reason)
        ->and($message->sent_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'communication_suppressed')->where('auditable_id', $message->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'communication_failed')->where('auditable_id', $message->id)->exists())->toBeFalse();
}

test('a candidate who opts out after a message was queued is not sent it, and it is not counted as a failure', function (): void {
    Event::fake([CommunicationFailed::class]);
    $context = deliveryContext();
    $message = $this->communications->send(CommunicationChannel::Sms, $context, deliveryTemplate('interview_scheduled', CommunicationChannel::Sms), trigger: CommunicationTrigger::Event, idempotencyKey: 'optout-1');
    Http::fake();

    app(CommunicationPreferenceService::class)->set($context->candidate, CommunicationChannel::Sms, PreferenceStatus::OptedOut, 'provider_opt_out');

    expectSuppressed(deliverNow($message), 'opted out');
    Http::assertNothingSent();
    Event::assertNotDispatched(CommunicationFailed::class);
});

test('a message queued for an open application is not sent once the application closes; one queued after closing is', function (): void {
    $context = deliveryContext();
    $beforeClosing = $this->communications->send(CommunicationChannel::Email, $context, deliveryTemplate('interview_scheduled'), trigger: CommunicationTrigger::Event, idempotencyKey: 'open-1');

    CandidateApplication::query()->whereKey($context->application->id)->update(['status' => ApplicationStatus::Rejected->value]);
    $rejectionNotice = $this->communications->send(CommunicationChannel::Email, new MessageContext($context->candidate, $context->application->fresh()), deliveryTemplate('application_rejected', body: 'Thank you {{candidate.first_name}}.'), trigger: CommunicationTrigger::Automation, idempotencyKey: 'closed-1');

    expectSuppressed(deliverNow($beforeClosing), 'application was closed');
    expect(deliverNow($rejectionNotice)->status)->toBe(CommunicationStatus::Sent);
});

test('an interview message is not sent after the interview is cancelled or moved; the cancellation notice is', function (): void {
    $cancelled = deliveryContext();
    $confirmation = $this->communications->send(CommunicationChannel::Email, $cancelled, deliveryTemplate('interview_scheduled'), trigger: CommunicationTrigger::Event, idempotencyKey: 'iv-1');
    Interview::query()->whereKey($cancelled->interview->id)->update(['status' => InterviewStatus::Cancelled->value]);
    $cancellationNotice = $this->communications->send(CommunicationChannel::Email, MessageContext::forInterview($cancelled->interview->fresh()), deliveryTemplate('interview_cancelled', body: 'Your interview is cancelled.'), trigger: CommunicationTrigger::Event, idempotencyKey: 'iv-2');

    $moved = deliveryContext();
    $reminder = $this->communications->send(CommunicationChannel::Email, $moved, CommunicationTemplate::query()->where('key', 'interview_scheduled')->sole(), trigger: CommunicationTrigger::Reminder, idempotencyKey: 'iv-3');
    Interview::query()->whereKey($moved->interview->id)->update(['scheduled_at' => now()->addDays(5)]);

    expectSuppressed(deliverNow($confirmation), 'Cancelled');
    expectSuppressed(deliverNow($reminder), 'moved to another time');
    expect(deliverNow($cancellationNotice)->status)->toBe(CommunicationStatus::Sent);
});

test('an offer message is not sent once the offer is withdrawn', function (): void {
    $context = deliveryContext();
    $offer = Offer::factory()->create(['candidate_application_id' => $context->application->id, 'status' => OfferStatus::Released]);
    $message = $this->communications->send(CommunicationChannel::Email, new MessageContext($context->candidate, $context->application, offer: $offer), deliveryTemplate('offer_released', body: 'Your offer is ready.'), trigger: CommunicationTrigger::Event, idempotencyKey: 'offer-1');

    Offer::query()->whereKey($offer->id)->update(['status' => OfferStatus::Withdrawn->value]);

    expectSuppressed(deliverNow($message), 'Withdrawn');
});

test('after the candidate joins, recruitment messages already queued are dropped but joining messages still go', function (): void {
    $context = deliveryContext();
    $recruitment = $this->communications->send(CommunicationChannel::Email, $context, deliveryTemplate('interview_feedback_request', body: 'How did it go?'), trigger: CommunicationTrigger::Automation, idempotencyKey: 'hire-1');
    $joining = $this->communications->send(CommunicationChannel::Email, new MessageContext($context->candidate, $context->application), deliveryTemplate('joining_reminder', body: 'See you on day one.'), trigger: CommunicationTrigger::Reminder, idempotencyKey: 'hire-2');

    CandidateApplication::query()->whereKey($context->application->id)->update(['current_stage' => CandidateStage::Joined->value]);

    expectSuppressed(deliverNow($recruitment), 'has joined');
    expect(deliverNow($joining)->status)->toBe(CommunicationStatus::Sent);
});

test('archiving a template stops its queued messages; editing it does not change what was queued', function (): void {
    $archivedTemplate = deliveryTemplate('interview_scheduled', body: 'Original wording for {{candidate.first_name}}.');
    $archived = $this->communications->send(CommunicationChannel::Email, deliveryContext(), $archivedTemplate, trigger: CommunicationTrigger::Event, idempotencyKey: 'tpl-1');
    $edited = $this->communications->send(CommunicationChannel::Email, deliveryContext(), $archivedTemplate, trigger: CommunicationTrigger::Event, idempotencyKey: 'tpl-2');

    AuditLog::withReason('Wording fix', fn () => app(CommunicationTemplateService::class)->update($archivedTemplate, ['body' => 'New wording.']));
    expect(deliverNow($edited)->body)->toBe('Original wording for Asha.');

    CommunicationTemplate::query()->whereKey($archivedTemplate->id)->update(['status' => TemplateStatus::Archived->value]);
    expectSuppressed(deliverNow($archived), 'archived');
});

test('a paused provider holds messages as queued without spending attempts, and the sweep drains them when it recovers', function (): void {
    config(['communications.circuit.threshold' => 2]);
    Http::fake(['api.twilio.com/*' => Http::sequence()->push(['message' => 'busy'], 503)->push(['message' => 'busy'], 503)->push(['sid' => 'SM1'], 201)]);
    $template = deliveryTemplate('interview_scheduled', CommunicationChannel::Sms, 'Interview {{interview.date}}');
    $messages = collect(range(1, 3))->map(fn (int $n) => $this->communications->send(CommunicationChannel::Sms, deliveryContext(), $template, trigger: CommunicationTrigger::Event, idempotencyKey: "outage-{$n}"));

    expect(fn () => deliverNow($messages[0]))->toThrow(RuntimeException::class);
    deliverNow($messages[1]);
    deliverNow($messages[2]);

    expect(app(ProviderCircuitBreaker::class)->isOpen('twilio'))->toBeTrue()
        ->and($messages->map(fn ($m) => $m->fresh()->status)->unique()->all())->toBe([CommunicationStatus::Queued])
        ->and($messages[2]->fresh()->attempts)->toBe(0);

    app(ProviderCircuitBreaker::class)->reset('twilio');
    CandidateCommunication::query()->update(['queued_at' => now()->subMinutes(15)]);
    Queue::fake();

    $this->artisan('reliability:sweep')->assertSuccessful();
    Queue::assertPushed(SendCommunicationJob::class, 3);

    expect(deliverNow($messages[2])->status)->toBe(CommunicationStatus::Sent);
});

test('mail accepted by a log or array mailer is recorded as not delivered externally', function (): void {
    $message = deliverNow($this->communications->send(CommunicationChannel::Email, deliveryContext(), deliveryTemplate('interview_scheduled'), trigger: CommunicationTrigger::Event, idempotencyKey: 'ext-1'));

    expect($message->status)->toBe(CommunicationStatus::Sent)
        ->and($message->delivered_externally)->toBeFalse();
});

test('a message stuck sending is failed for an operator to check, never resent automatically', function (): void {
    Event::fake([CommunicationFailed::class]);
    $message = CandidateCommunication::factory()->create();
    CandidateCommunication::query()->whereKey($message->id)->update(['status' => CommunicationStatus::Sending->value, 'updated_at' => now()->subHour()]);

    $this->artisan('reliability:sweep')->assertSuccessful();

    expect($message->fresh()->status)->toBe(CommunicationStatus::Failed)
        ->and($message->fresh()->error)->toContain('Delivery state unknown');
    Queue::assertNotPushed(SendCommunicationJob::class);
    Event::assertDispatched(CommunicationFailed::class);
});

test('a double-submitted manual message is queued once', function (): void {
    $context = deliveryContext();
    $actor = Employee::factory()->create();

    $first = $this->communications->send(CommunicationChannel::Email, $context, subject: 'Hello', body: 'Checking in.', actor: $actor);
    $second = $this->communications->send(CommunicationChannel::Email, $context, subject: 'Hello', body: 'Checking in.', actor: $actor);

    expect($second->id)->toBe($first->id)
        ->and(CandidateCommunication::query()->count())->toBe(1);
});

test('an operator resends a failed message as a new, audited message with the same content', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('vp_hr');
    actingAs($user);
    $original = $this->communications->send(CommunicationChannel::Email, deliveryContext(['recruiter_id' => $user->employee_id]), deliveryTemplate('interview_scheduled'), trigger: CommunicationTrigger::Event, idempotencyKey: 'resend-src');
    CandidateCommunication::query()->whereKey($original->id)->update(['status' => CommunicationStatus::Failed->value]);

    Livewire::test(ViewCandidateCommunication::class, ['record' => $original->id])
        ->callAction('resend', ['reason' => 'Provider outage on Monday'])
        ->assertHasNoActionErrors();

    $resent = CandidateCommunication::query()->where('idempotency_key', "resend:{$original->id}:1")->sole();
    $audit = AuditLog::query()->where('action', 'communication_resent')->where('auditable_id', $resent->id)->sole();

    expect($resent->status)->toBe(CommunicationStatus::Queued)
        ->and($resent->body)->toBe($original->body)
        ->and($resent->metadata['resent_from'])->toBe($original->public_id)
        ->and($audit->reason)->toBe('Provider outage on Monday')
        ->and($original->fresh()->status)->toBe(CommunicationStatus::Failed);
    Queue::assertPushed(SendCommunicationJob::class, fn ($job) => $job->communicationId === $resent->id);
});

test('resend requires the send permission, a failed message, and the candidate\'s consent', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $viewer = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->givePermissionTo('communications.view');
    $context = deliveryContext();
    $message = $this->communications->send(CommunicationChannel::Email, $context, deliveryTemplate('interview_scheduled'), trigger: CommunicationTrigger::Event, idempotencyKey: 'resend-guard');

    expect(fn () => $this->communications->resend($message, Employee::factory()->create(), 'retry'))->toThrow(DomainException::class, 'Only a failed or bounced');

    CandidateCommunication::query()->whereKey($message->id)->update(['status' => CommunicationStatus::Failed->value]);
    app(CommunicationPreferenceService::class)->set($context->candidate, CommunicationChannel::Email, PreferenceStatus::OptedOut, 'candidate_portal');

    expect($viewer->can('resend', $message->fresh()))->toBeFalse()
        ->and($this->communications->resend($message->fresh(), Employee::factory()->create(), 'retry')->status)->toBe(CommunicationStatus::Blocked);
});

test('an AI action interrupted while running is marked failed with an honest note, not left approved forever', function (): void {
    $stuck = AiToolCall::factory()->create(['status' => AiToolCallStatus::Approved, 'approved_at' => now()->subHour()]);
    $running = AiToolCall::factory()->create(['status' => AiToolCallStatus::Approved, 'approved_at' => now()->subMinute()]);

    $this->artisan('reliability:sweep')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(AiToolCallStatus::Failed)
        ->and($stuck->result->error)->toContain('may have partly completed')
        ->and($running->fresh()->status)->toBe(AiToolCallStatus::Approved)
        ->and(AuditLog::query()->where('action', 'ai_action_interrupted')->where('auditable_id', $stuck->id)->exists())->toBeTrue();
});

test('the candidate portal timeline shows a message only once it has actually gone out', function (): void {
    $context = deliveryContext();
    $sent = $this->communications->send(CommunicationChannel::Email, $context, deliveryTemplate('interview_scheduled'), trigger: CommunicationTrigger::Event, idempotencyKey: 'portal-1');
    $suppressed = $this->communications->send(CommunicationChannel::Email, $context, deliveryTemplate('application_received', body: 'We received your application.'), trigger: CommunicationTrigger::Event, idempotencyKey: 'portal-2');
    $waiting = $this->communications->send(CommunicationChannel::Email, $context, deliveryTemplate('document_request', body: 'Please upload your documents.'), trigger: CommunicationTrigger::Event, idempotencyKey: 'portal-3');

    deliverNow($sent);
    CommunicationTemplate::query()->where('key', 'application_received')->update(['status' => TemplateStatus::Archived->value]);
    deliverNow($suppressed);

    $titles = app(CandidateTimelineService::class)->forPortal($context->candidate)->pluck('title')->implode(' | ');

    expect($titles)->toContain($sent->fresh()->subject)
        ->and(substr_count($titles, 'Email:'))->toBe(1)
        ->and($waiting->fresh()->status)->toBe(CommunicationStatus::Queued);
});

test('a delivery report that arrives before the message id is saved is held and applied, not lost', function (): void {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM-EARLY-1'], 201)]);
    $message = $this->communications->send(CommunicationChannel::Sms, deliveryContext(), deliveryTemplate('interview_scheduled', CommunicationChannel::Sms, 'Interview {{interview.date}}'), trigger: CommunicationTrigger::Event, idempotencyKey: 'early-1');

    $outcome = app(DeliveryStatusService::class)->apply('twilio', new WebhookStatusUpdate('evt-early-1', 'SM-EARLY-1', CommunicationStatus::Delivered, now()), 'hash-early-1', CommunicationChannel::Sms);

    expect($outcome)->toBe('ignored')
        ->and(deliverNow($message)->status)->toBe(CommunicationStatus::Delivered)
        ->and($message->fresh()->delivered_at)->not->toBeNull();
});

test('running the sweep again changes nothing more: each stuck item is handled once', function (): void {
    Event::fake([CommunicationFailed::class]);
    $sending = CandidateCommunication::factory()->create();
    CandidateCommunication::query()->whereKey($sending->id)->update(['status' => CommunicationStatus::Sending->value, 'updated_at' => now()->subHour()]);
    $interrupted = AiToolCall::factory()->create(['status' => AiToolCallStatus::Approved, 'approved_at' => now()->subHour()]);

    $this->artisan('reliability:sweep')->assertSuccessful();
    $this->artisan('reliability:sweep')->assertSuccessful();

    Event::assertDispatchedTimes(CommunicationFailed::class, 1);
    expect(AuditLog::query()->where('action', 'communication_failed')->where('auditable_id', $sending->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'ai_action_interrupted')->where('auditable_id', $interrupted->id)->count())->toBe(1)
        ->and($interrupted->fresh()->result()->count())->toBe(1);
});
