<?php

namespace App\Services\Communication\Providers;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Services\Communication\Contracts\CommunicationProvider;
use App\Services\Communication\Contracts\HandlesDeliveryWebhooks;
use App\Services\Communication\Data\DeliveryResult;
use App\Services\Communication\Data\IntegrationTestResult;
use App\Services\Communication\Data\OutboundMessage;
use App\Services\Communication\Data\WebhookStatusUpdate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Twilio Programmable SMS adapter. Status callbacks are authenticated with X-Twilio-Signature
 * (HMAC-SHA1 of the full URL + sorted POST params, keyed by the auth token). Twilio handles
 * STOP keywords itself; an inbound "STOP" callback is also recorded as an opt-out here.
 * Credentials: config('services.twilio').
 */
class TwilioSmsProvider implements CommunicationProvider, HandlesDeliveryWebhooks
{
    public function key(): string
    {
        return 'twilio';
    }

    public function label(): string
    {
        return 'Twilio SMS';
    }

    public function category(): string
    {
        return 'communication';
    }

    public function channel(): CommunicationChannel
    {
        return CommunicationChannel::Sms;
    }

    public function isConfigured(): bool
    {
        return filled(config('services.twilio.sid')) && filled(config('services.twilio.token')) && filled(config('services.twilio.from'));
    }

    public function deliversExternally(): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth((string) config('services.twilio.sid'), (string) config('services.twilio.token'))
                ->timeout(15)
                ->post($this->endpoint('Messages.json'), [
                    'To' => $message->recipient,
                    'From' => config('services.twilio.from'),
                    'Body' => $message->body,
                    'StatusCallback' => route('webhooks.communications', ['provider' => $this->key()]),
                ]);
        } catch (ConnectionException $e) {
            return DeliveryResult::failed('Twilio unreachable: '.$e->getMessage(), retryable: true);
        }

        if ($response->successful() && filled($sid = $response->json('sid'))) {
            return DeliveryResult::sent($sid);
        }

        return DeliveryResult::failed(
            'Twilio error '.$response->status().': '.($response->json('message') ?? 'unknown error'),
            retryable: $response->status() === 429 || $response->serverError(),
        );
    }

    public function testConnection(): IntegrationTestResult
    {
        if (! $this->isConfigured()) {
            return new IntegrationTestResult(false, 'Twilio credentials are not configured.');
        }

        try {
            $response = Http::withBasicAuth((string) config('services.twilio.sid'), (string) config('services.twilio.token'))
                ->timeout(10)
                ->get('https://api.twilio.com/2010-04-01/Accounts/'.config('services.twilio.sid').'.json');
        } catch (ConnectionException $e) {
            return new IntegrationTestResult(false, 'Twilio unreachable: '.$e->getMessage());
        }

        return $response->successful()
            ? new IntegrationTestResult(true, 'Connected to Twilio account ('.($response->json('status') ?? 'unknown status').').')
            : new IntegrationTestResult(false, 'Twilio rejected the credentials ('.$response->status().').');
    }

    public function verifyWebhook(Request $request): bool
    {
        $token = (string) config('services.twilio.token');
        $signature = (string) $request->header('X-Twilio-Signature');

        if ($token === '' || $signature === '') {
            return false;
        }

        $params = $request->request->all();
        ksort($params);
        $data = $request->fullUrl();

        foreach ($params as $key => $value) {
            $data .= $key.$value;
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, $token, true)), $signature);
    }

    public function parseWebhook(Request $request): array
    {
        $sid = (string) $request->input('MessageSid');

        if ($request->filled('Body') && in_array(strtoupper(trim((string) $request->input('Body'))), ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
            return [new WebhookStatusUpdate($sid.':inbound', null, null, now(), optOutRecipient: (string) $request->input('From'), eventType: 'inbound.opt_out')];
        }

        $status = (string) $request->input('MessageStatus');

        return [new WebhookStatusUpdate(
            providerEventId: $sid.':'.$status,
            providerMessageId: $sid,
            status: match ($status) {
                'sent' => CommunicationStatus::Sent,
                'delivered' => CommunicationStatus::Delivered,
                'read' => CommunicationStatus::Read,
                'undelivered' => CommunicationStatus::Bounced,
                'failed' => CommunicationStatus::Failed,
                default => null,
            },
            occurredAt: now(),
            error: $request->input('ErrorCode') !== null ? 'Twilio error code '.$request->input('ErrorCode') : null,
            eventType: 'status.'.$status,
        )];
    }

    private function endpoint(string $path): string
    {
        return 'https://api.twilio.com/2010-04-01/Accounts/'.config('services.twilio.sid').'/'.$path;
    }
}
