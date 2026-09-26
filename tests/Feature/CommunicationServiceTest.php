<?php

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\CommunicationTrigger;
use App\Enums\PreferenceStatus;
use App\Enums\TemplateStatus;
use App\Enums\TimelineVisibility;
use App\Jobs\SendCommunicationJob;
use App\Mail\CandidateMessageMail;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CommunicationTemplate;
use App\Models\Interview;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\CommunicationTemplateService;
use App\Services\Communication\MessageContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['mail.default' => 'array', 'mail.from.address' => 'hiring@example.com']);
    $this->communications = app(CommunicationService::class);
    $this->templates = app(CommunicationTemplateService::class);
});

function commTemplate(CommunicationChannel $channel = CommunicationChannel::Email, string $body = 'Hi {{candidate.first_name}}, your interview is on {{interview.date}}.', array $extra = []): CommunicationTemplate
{
    return app(CommunicationTemplateService::class)->create([
        'key' => 'interview_scheduled',
        'name' => 'Interview confirmation',
        'channel' => $channel,
        'subject' => $channel === CommunicationChannel::Email ? 'Interview for {{requisition.code}}' : null,
        'body' => $body,
        'status' => TemplateStatus::Active,
        ...$extra,
    ]);
}

function commContext(array $candidate = []): MessageContext
{
    $application = CandidateApplication::factory()->create(['candidate_id' => Candidate::factory()->create(['full_name' => 'Rahul Sharma', 'email' => 'rahul@example.com', 'mobile' => '9876543210', ...$candidate])->id]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'scheduled_at' => now()->addDays(2)->setTime(10, 30)]);

    return MessageContext::forInterview($interview);
}

test('a templated email is rendered, recorded as queued, put on the timeline, audited and queued for sending', function (): void {
    Queue::fake();
    $context = commContext();

    $message = $this->communications->send(CommunicationChannel::Email, $context, commTemplate());

    expect($message->status)->toBe(CommunicationStatus::Queued)
        ->and($message->recipient)->toBe('rahul@example.com')
        ->and($message->subject)->toBe('Interview for '.$context->application->requisition->code)
        ->and($message->body)->toBe('Hi Rahul, your interview is on '.$context->interview->scheduled_at->format('D, d M Y').'.')
        ->and($message->template_version)->toBe(1)
        ->and($context->candidate->timelineEvents()->where('event_type', 'email')->sole()->visibility)->toBe(TimelineVisibility::Candidate)
        ->and(AuditLog::query()->where('action', 'communication_queued')->where('auditable_id', $message->id)->exists())->toBeTrue();
    Queue::assertPushed(SendCommunicationJob::class, fn ($job) => $job->communicationId === $message->id && $job->queue === 'communications');
});

test('the send job hands the email to the provider and records the provider message id', function (): void {
    Mail::fake();
    $message = $this->communications->send(CommunicationChannel::Email, commContext(), commTemplate());

    expect($message->fresh()->status)->toBe(CommunicationStatus::Sent)
        ->and($message->fresh()->sent_at)->not->toBeNull()
        ->and($message->fresh()->attempts)->toBe(1);
    Mail::assertSent(CandidateMessageMail::class, fn (CandidateMessageMail $mail) => $mail->hasTo('rahul@example.com') && $mail->reference === $message->public_id);
});

test('an opted-out candidate is never sent a message; the block is recorded internally', function (): void {
    Queue::fake();
    $context = commContext();
    app(CommunicationPreferenceService::class)->set($context->candidate, CommunicationChannel::Email, PreferenceStatus::OptedOut, 'recruiter');

    $message = $this->communications->send(CommunicationChannel::Email, $context, commTemplate());

    expect($message->status)->toBe(CommunicationStatus::Blocked)
        ->and($message->blocked_reason)->toContain('opted out')
        ->and($context->candidate->timelineEvents()->where('event_type', 'email')->sole()->visibility)->toBe(TimelineVisibility::Internal)
        ->and(AuditLog::query()->where('action', 'communication_blocked')->exists())->toBeTrue();
    Queue::assertNothingPushed();
});

test('WhatsApp needs explicit consent', function (): void {
    Queue::fake();
    config(['services.whatsapp_cloud' => ['token' => 't', 'phone_number_id' => '1', 'app_secret' => 's', 'api_version' => 'v20.0']]);
    $context = commContext();
    $template = commTemplate(CommunicationChannel::WhatsApp, 'Interview {{interview.date}}', ['provider_template' => 'interview_confirmation']);

    expect($this->communications->send(CommunicationChannel::WhatsApp, $context, $template)->blocked_reason)->toContain('not consented');

    app(CommunicationPreferenceService::class)->set($context->candidate, CommunicationChannel::WhatsApp, PreferenceStatus::Allowed, 'recruiter');

    expect($this->communications->send(CommunicationChannel::WhatsApp, $context, $template)->status)->toBe(CommunicationStatus::Queued);
});

test('a message on a channel with no configured provider is blocked, never faked as sent', function (): void {
    Queue::fake();
    config(['services.twilio' => ['sid' => null, 'token' => null, 'from' => null]]);

    $message = $this->communications->send(CommunicationChannel::Sms, commContext(), commTemplate(CommunicationChannel::Sms));

    expect($message->status)->toBe(CommunicationStatus::Blocked)
        ->and($message->blocked_reason)->toBe('No SMS provider is configured.');
});

test('the WhatsApp adapter sends a provider template with ordered parameters', function (): void {
    config(['services.whatsapp_cloud' => ['token' => 't', 'phone_number_id' => '123', 'app_secret' => 's', 'api_version' => 'v20.0']]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]])]);
    $context = commContext();
    app(CommunicationPreferenceService::class)->set($context->candidate, CommunicationChannel::WhatsApp, PreferenceStatus::Allowed, 'recruiter');

    $message = $this->communications->send(CommunicationChannel::WhatsApp, $context, commTemplate(CommunicationChannel::WhatsApp, 'Hi {{candidate.first_name}}, {{interview.date}}', ['provider_template' => 'interview_confirmation']));

    expect($message->fresh()->status)->toBe(CommunicationStatus::Sent)->and($message->fresh()->provider_message_id)->toBe('wamid.ABC');
    Http::assertSent(fn ($request) => $request['to'] === '919876543210'
        && $request['template']['name'] === 'interview_confirmation'
        && $request['template']['components'][0]['parameters'][0]['text'] === 'Rahul');
});

test('a temporary provider failure is retried, and exhausting retries marks the message failed', function (): void {
    config(['services.twilio' => ['sid' => 'AC1', 'token' => 'tok', 'from' => '+15550001111'], 'communications.tries' => 2]);
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'busy'], 503)]);
    Queue::fake();
    $message = $this->communications->send(CommunicationChannel::Sms, commContext(), commTemplate(CommunicationChannel::Sms, 'Interview {{interview.date}}'));
    $job = new SendCommunicationJob($message->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'Temporary provider failure');
    expect($message->fresh()->status)->toBe(CommunicationStatus::Queued)->and($message->fresh()->error)->toContain('503');

    $job->failed(new RuntimeException('exhausted'));

    expect($message->fresh()->status)->toBe(CommunicationStatus::Failed)
        ->and(AuditLog::query()->where('action', 'communication_failed')->where('auditable_id', $message->id)->exists())->toBeTrue();
});

test('a permanent provider failure is not retried', function (): void {
    config(['services.twilio' => ['sid' => 'AC1', 'token' => 'tok', 'from' => '+15550001111']]);
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'invalid number'], 400)]);

    $message = $this->communications->send(CommunicationChannel::Sms, commContext(), commTemplate(CommunicationChannel::Sms, 'Interview {{interview.date}}'));

    expect($message->fresh()->status)->toBe(CommunicationStatus::Failed)->and($message->fresh()->error)->toContain('invalid number');
    Http::assertSentCount(1);
});

test('the same idempotency key never creates or sends a second message', function (): void {
    Mail::fake();
    $context = commContext();
    $template = commTemplate();

    $first = $this->communications->send(CommunicationChannel::Email, $context, $template, idempotencyKey: 'interview.scheduled:1:email');
    $second = $this->communications->send(CommunicationChannel::Email, $context, $template, idempotencyKey: 'interview.scheduled:1:email');

    expect($second->id)->toBe($first->id)->and(CandidateCommunication::query()->count())->toBe(1);
    Mail::assertSentCount(1);
});

test('a message interrupted mid-send is not resent, to avoid a duplicate', function (): void {
    Mail::fake();
    Queue::fake();
    $message = $this->communications->send(CommunicationChannel::Email, commContext(), commTemplate());
    $message->forceFill(['status' => CommunicationStatus::Sending])->save();

    app()->call([new SendCommunicationJob($message->id), 'handle']);

    expect($message->fresh()->status)->toBe(CommunicationStatus::Failed)->and($message->fresh()->error)->toContain('not retried');
    Mail::assertNothingSent();
});

test('unknown template variables are rejected', function (): void {
    $this->templates->create(['key' => 'x', 'name' => 'X', 'channel' => 'email', 'subject' => 'Hi', 'body' => 'Salary: {{candidate.expected_salary}}']);
})->throws(DomainException::class, 'Unknown template variable');

test('a manual message missing context for a variable is refused; an automatic one is blocked', function (): void {
    Queue::fake();
    $candidate = Candidate::factory()->create(['email' => 'a@example.com']);

    expect(fn () => $this->communications->send(CommunicationChannel::Email, new MessageContext($candidate), subject: 'Hi', body: 'On {{interview.date}}'))
        ->toThrow(DomainException::class, 'No value is available for {{interview.date}}');

    $automatic = $this->communications->send(CommunicationChannel::Email, new MessageContext($candidate), commTemplate(), trigger: CommunicationTrigger::Event);

    expect($automatic->status)->toBe(CommunicationStatus::Blocked);
});

test('template edits bump the version and keep every wording', function (): void {
    $template = commTemplate();

    $this->templates->update($template, ['body' => 'Updated {{candidate.name}}']);
    $this->templates->update($template, ['name' => 'Renamed']);

    expect($template->fresh()->version)->toBe(2)
        ->and($template->versions()->pluck('body')->all())->toBe(['Updated {{candidate.name}}', 'Hi {{candidate.first_name}}, your interview is on {{interview.date}}.']);
});

test('sendAutomatic only uses active templates', function (): void {
    Queue::fake();
    $context = commContext();
    commTemplate()->update(['status' => TemplateStatus::Draft]);

    expect($this->communications->sendAutomatic('interview_scheduled', $context, 'x:1'))->toBe([]);
});
