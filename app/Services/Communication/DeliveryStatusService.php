<?php

namespace App\Services\Communication;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\PreferenceStatus;
use App\Events\CommunicationFailed;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateCommunication;
use App\Models\CommunicationWebhookEvent;
use App\Services\CandidateIdentityNormalizer;
use App\Services\Communication\Data\WebhookStatusUpdate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies verified provider webhook events (Phase 5): delivery status and opt-outs. Each provider
 * event id is processed at most once (communication_webhook_events unique key = replay
 * protection), statuses only ever move forward (an out-of-order "sent" never overwrites "read"),
 * and only data the provider actually reported is recorded.
 */
class DeliveryStatusService
{
    public function __construct(private readonly CommunicationPreferenceService $preferences) {}

    /**
     * @return string processed | duplicate | ignored
     */
    public function apply(string $provider, WebhookStatusUpdate $update, string $payloadHash, CommunicationChannel $channel): string
    {
        try {
            return DB::transaction(function () use ($provider, $update, $payloadHash, $channel): string {
                $event = CommunicationWebhookEvent::query()->create([
                    'provider' => $provider,
                    'provider_event_id' => mb_substr($update->providerEventId, 0, 250),
                    'event_type' => $update->eventType,
                    'payload_hash' => $payloadHash,
                    'received_at' => now(),
                ]);

                $note = $update->optOutRecipient !== null
                    ? $this->applyOptOut($update->optOutRecipient, $channel)
                    : $this->applyStatus($provider, $update);

                $event->update(['status' => $note === null ? 'processed' : 'ignored', 'note' => $note]);

                return $note === null ? 'processed' : 'ignored';
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }
    }

    private function applyStatus(string $provider, WebhookStatusUpdate $update): ?string
    {
        if ($update->status === null || $update->providerMessageId === null) {
            return 'unsupported event';
        }

        $communication = CandidateCommunication::query()
            ->where('provider', $provider)
            ->where('provider_message_id', $update->providerMessageId)
            ->lockForUpdate()
            ->first();

        if ($communication === null) {
            return 'unknown message';
        }

        if ($update->status->rank() <= $communication->status->rank()) {
            return 'stale status';
        }

        $timestamp = match ($update->status) {
            CommunicationStatus::Sent => 'sent_at',
            CommunicationStatus::Delivered => 'delivered_at',
            CommunicationStatus::Read => 'read_at',
            CommunicationStatus::Failed, CommunicationStatus::Bounced => 'failed_at',
            default => null,
        };

        $communication->forceFill(array_filter([
            'status' => $update->status,
            $timestamp => $update->occurredAt,
            'error' => $update->error,
        ], fn ($value) => $value !== null))->save();

        if (in_array($update->status, [CommunicationStatus::Failed, CommunicationStatus::Bounced], true)) {
            AuditLog::record($communication, 'communication_failed', null, ['provider' => $provider, 'status' => $update->status->value, 'error' => $update->error, 'source' => 'webhook']);
            CommunicationFailed::dispatch($communication);
        }

        return null;
    }

    private function applyOptOut(string $recipient, CommunicationChannel $channel): ?string
    {
        $candidates = $channel === CommunicationChannel::Email
            ? Candidate::query()->where('email_normalized', CandidateIdentityNormalizer::email($recipient))->get()
            : Candidate::query()->where('mobile_normalized', CandidateIdentityNormalizer::mobile($recipient))->get();

        if ($candidates->isEmpty()) {
            return 'opt-out for unknown recipient';
        }

        $candidates->each(fn (Candidate $candidate) => $this->preferences->set($candidate, $channel, PreferenceStatus::OptedOut, 'provider_opt_out', reason: 'Opted out by replying to a '.$channel->label().' message'));

        return null;
    }
}
