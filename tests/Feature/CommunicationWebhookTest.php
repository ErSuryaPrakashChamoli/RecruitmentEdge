<?php

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\PreferenceStatus;
use App\Models\Candidate;
use App\Models\CandidateCommunication;
use App\Models\CommunicationWebhookEvent;
use App\Services\Communication\CommunicationPreferenceService;

beforeEach(function (): void {
    config(['services.whatsapp_cloud' => ['token' => 't', 'phone_number_id' => '1', 'app_secret' => 'app-secret', 'verify_token' => 'verify-me', 'api_version' => 'v20.0']]);
    config(['services.twilio' => ['sid' => 'AC1', 'token' => 'twilio-token', 'from' => '+15550001111']]);
});

function sentWhatsApp(string $providerId = 'wamid.1'): CandidateCommunication
{
    $message = CandidateCommunication::factory()->create(['channel' => CommunicationChannel::WhatsApp]);
    $message->forceFill(['status' => CommunicationStatus::Sent, 'provider' => 'whatsapp_cloud', 'provider_message_id' => $providerId])->save();

    return $message;
}

function whatsAppWebhook(array $statuses = [], array $messages = []): array
{
    return ['entry' => [['changes' => [['value' => ['statuses' => $statuses, 'messages' => $messages]]]]]];
}

function postSignedWhatsApp($test, array $payload, ?string $secret = 'app-secret')
{
    $body = json_encode($payload);

    return $test->call('POST', route('webhooks.communications', 'whatsapp_cloud'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
    ], $body);
}

test('a correctly signed WhatsApp status webhook updates delivery status', function (): void {
    $message = sentWhatsApp();

    postSignedWhatsApp($this, whatsAppWebhook([['id' => 'wamid.1', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp]]))->assertOk();

    expect($message->fresh()->status)->toBe(CommunicationStatus::Delivered)->and($message->fresh()->delivered_at)->not->toBeNull();
});

test('a webhook with a bad or missing signature is rejected and changes nothing', function (?string $secret): void {
    $message = sentWhatsApp();

    postSignedWhatsApp($this, whatsAppWebhook([['id' => 'wamid.1', 'status' => 'read', 'timestamp' => (string) now()->timestamp]]), $secret)->assertUnauthorized();

    expect($message->fresh()->status)->toBe(CommunicationStatus::Sent);
})->with(['wrong secret' => ['not-the-secret'], 'empty secret' => ['']]);

test('a replayed webhook event is processed only once', function (): void {
    sentWhatsApp();
    $payload = whatsAppWebhook([['id' => 'wamid.1', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp]]);

    postSignedWhatsApp($this, $payload)->assertOk();
    postSignedWhatsApp($this, $payload)->assertOk();

    expect(CommunicationWebhookEvent::query()->count())->toBe(1);
});

test('an out-of-order status never moves a message backwards', function (): void {
    $message = sentWhatsApp();

    postSignedWhatsApp($this, whatsAppWebhook([['id' => 'wamid.1', 'status' => 'read', 'timestamp' => (string) now()->timestamp]]));
    postSignedWhatsApp($this, whatsAppWebhook([['id' => 'wamid.1', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp]]));

    expect($message->fresh()->status)->toBe(CommunicationStatus::Read);
});

test('a STOP reply opts the candidate out of WhatsApp', function (): void {
    $candidate = Candidate::factory()->create(['mobile' => '9876543210']);
    app(CommunicationPreferenceService::class)->set($candidate, CommunicationChannel::WhatsApp, PreferenceStatus::Allowed, 'recruiter');

    postSignedWhatsApp($this, whatsAppWebhook(messages: [['id' => 'wamid.in1', 'from' => '919876543210', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'stop']]]))->assertOk();

    expect(app(CommunicationPreferenceService::class)->statusFor($candidate, CommunicationChannel::WhatsApp))->toBe(PreferenceStatus::OptedOut);
});

test('the WhatsApp subscription handshake only answers with the right verify token', function (): void {
    $this->get(route('webhooks.communications.verify', 'whatsapp_cloud').'?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')->assertOk()->assertSee('12345');
    $this->get(route('webhooks.communications.verify', 'whatsapp_cloud').'?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')->assertForbidden();
});

test('a Twilio status callback is authenticated with X-Twilio-Signature', function (): void {
    $message = CandidateCommunication::factory()->create(['channel' => CommunicationChannel::Sms]);
    $message->forceFill(['status' => CommunicationStatus::Sent, 'provider' => 'twilio', 'provider_message_id' => 'SM123'])->save();
    $url = route('webhooks.communications', 'twilio');
    $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003'];
    ksort($params);
    $signature = base64_encode(hash_hmac('sha1', $url.implode('', array_map(fn ($k, $v) => $k.$v, array_keys($params), $params)), 'twilio-token', true));

    $this->post($url, $params, ['X-Twilio-Signature' => 'forged'])->assertUnauthorized();
    $this->post($url, $params, ['X-Twilio-Signature' => $signature])->assertOk();

    expect($message->fresh()->status)->toBe(CommunicationStatus::Bounced)->and($message->fresh()->error)->toContain('30003');
});

test('webhooks for unknown providers are not found', function (): void {
    $this->post(route('webhooks.communications', 'nope'), [])->assertNotFound();
});
