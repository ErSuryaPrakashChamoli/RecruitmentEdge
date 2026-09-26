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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Meta WhatsApp Business Cloud API adapter. Business-initiated messages must use a
 * provider-approved template (communication_templates.provider_template); free text is only valid
 * inside the customer-service window, which the platform does not track, so a template is required.
 * Delivery statuses and "STOP" opt-outs arrive by webhook, authenticated with the app secret
 * (X-Hub-Signature-256). Credentials: config('services.whatsapp_cloud').
 */
class WhatsAppCloudProvider implements CommunicationProvider, HandlesDeliveryWebhooks
{
    public function key(): string
    {
        return 'whatsapp_cloud';
    }

    public function label(): string
    {
        return 'WhatsApp Business Cloud API';
    }

    public function category(): string
    {
        return 'communication';
    }

    public function channel(): CommunicationChannel
    {
        return CommunicationChannel::WhatsApp;
    }

    public function isConfigured(): bool
    {
        return filled($this->config('token')) && filled($this->config('phone_number_id')) && filled($this->config('app_secret'));
    }

    public function deliversExternally(): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        if (blank($message->providerTemplate)) {
            return DeliveryResult::failed('WhatsApp messages need a provider-approved template (set "Provider template" on the communication template).', retryable: false);
        }

        try {
            $response = Http::withToken((string) $this->config('token'))
                ->timeout(15)
                ->post($this->endpoint("{$this->config('phone_number_id')}/messages"), [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($message->recipient, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $message->providerTemplate,
                        'language' => ['code' => $message->language],
                        'components' => $message->templateParameters === [] ? [] : [[
                            'type' => 'body',
                            'parameters' => array_map(fn (string $value) => ['type' => 'text', 'text' => $value], $message->templateParameters),
                        ]],
                    ],
                    'biz_opaque_callback_data' => $message->reference,
                ]);
        } catch (ConnectionException $e) {
            return DeliveryResult::failed('WhatsApp API unreachable: '.$e->getMessage(), retryable: true);
        }

        if ($response->successful() && filled($id = $response->json('messages.0.id'))) {
            return DeliveryResult::sent($id);
        }

        return DeliveryResult::failed(
            'WhatsApp API error '.$response->status().': '.($response->json('error.message') ?? 'unknown error'),
            retryable: $response->status() === 429 || $response->serverError(),
        );
    }

    public function testConnection(): IntegrationTestResult
    {
        if (! $this->isConfigured()) {
            return new IntegrationTestResult(false, 'WhatsApp Cloud API credentials are not configured.');
        }

        try {
            $response = Http::withToken((string) $this->config('token'))->timeout(10)->get($this->endpoint((string) $this->config('phone_number_id')));
        } catch (ConnectionException $e) {
            return new IntegrationTestResult(false, 'WhatsApp API unreachable: '.$e->getMessage());
        }

        return $response->successful()
            ? new IntegrationTestResult(true, 'Connected to WhatsApp number '.($response->json('display_phone_number') ?? $this->config('phone_number_id')).'.')
            : new IntegrationTestResult(false, 'WhatsApp API rejected the credentials ('.$response->status().').');
    }

    /**
     * Meta signs the raw body with the app secret: X-Hub-Signature-256: sha256=<hex hmac>.
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) $this->config('app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');

        return $secret !== ''
            && str_starts_with($signature, 'sha256=')
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    public function parseWebhook(Request $request): array
    {
        $updates = [];

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $updates[] = new WebhookStatusUpdate(
                        providerEventId: ($status['id'] ?? '').':'.($status['status'] ?? ''),
                        providerMessageId: $status['id'] ?? null,
                        status: match ($status['status'] ?? null) {
                            'sent' => CommunicationStatus::Sent,
                            'delivered' => CommunicationStatus::Delivered,
                            'read' => CommunicationStatus::Read,
                            'failed' => CommunicationStatus::Failed,
                            default => null,
                        },
                        occurredAt: Carbon::createFromTimestamp((int) ($status['timestamp'] ?? time())),
                        error: $status['errors'][0]['title'] ?? null,
                        eventType: 'status.'.($status['status'] ?? 'unknown'),
                    );
                }

                foreach ((array) ($value['messages'] ?? []) as $inbound) {
                    $text = strtoupper(trim((string) ($inbound['text']['body'] ?? '')));

                    if (in_array($text, ['STOP', 'UNSUBSCRIBE', 'STOP ALL'], true)) {
                        $updates[] = new WebhookStatusUpdate(
                            providerEventId: (string) ($inbound['id'] ?? ''),
                            providerMessageId: null,
                            status: null,
                            occurredAt: Carbon::createFromTimestamp((int) ($inbound['timestamp'] ?? time())),
                            optOutRecipient: '+'.ltrim((string) ($inbound['from'] ?? ''), '+'),
                            eventType: 'inbound.opt_out',
                        );
                    }
                }
            }
        }

        return $updates;
    }

    /**
     * Meta's subscription handshake (GET): echo hub.challenge when hub.verify_token matches.
     */
    public function verificationChallenge(Request $request): ?string
    {
        $expected = (string) $this->config('verify_token');

        return $expected !== ''
            && $request->query('hub_mode', $request->query('hub.mode')) === 'subscribe'
            && hash_equals($expected, (string) $request->query('hub_verify_token', $request->query('hub.verify_token')))
            ? (string) $request->query('hub_challenge', $request->query('hub.challenge'))
            : null;
    }

    private function endpoint(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->config('api_version').'/'.$path;
    }

    private function config(string $key): mixed
    {
        return config("services.whatsapp_cloud.{$key}");
    }
}
