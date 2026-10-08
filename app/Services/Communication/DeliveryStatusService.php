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
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

                [$note, $tenantId] = $update->optOutRecipient !== null
                    ? [$this->applyOptOut($update->optOutRecipient, $channel), null]
                    : $this->applyStatus($provider, $update);

                $event->update(['tenant_id' => $tenantId, 'status' => $note === null ? 'processed' : 'ignored', 'note' => $note]);

                return $note === null ? 'processed' : 'ignored';
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }
    }

    /**
     * SaaS-1: the callback names the provider's own message id (unique per provider), which
     * identifies the message — and so its tenant — after the signature was verified. The status is
     * then applied inside that tenant only.
     *
     * @return array{0: string|null, 1: int|null} the note (null = applied) and the tenant it applied in
     */
    private function applyStatus(string $provider, WebhookStatusUpdate $update): array
    {
        if ($update->status === null || $update->providerMessageId === null) {
            return ['unsupported event', null];
        }

        $tenantId = CandidateCommunication::query()
            ->withoutTenancy()
            ->where('provider', $provider)
            ->where('provider_message_id', $update->providerMessageId)
            ->value('tenant_id');

        if ($tenantId === null) {
            // Phase 8.7 (DQ-87-08): a fast provider can report delivery before the worker has saved
            // the message id. The status is held for an hour and applied when the id is recorded
            // (applyHeld) instead of being lost. Status, time and error only.
            $held = Cache::get($this->heldKey($provider, $update->providerMessageId), []);
            $held[] = ['status' => $update->status->value, 'occurred_at' => $update->occurredAt->toIso8601String(), 'error' => $update->error];
            Cache::put($this->heldKey($provider, $update->providerMessageId), $held, now()->addHour());

            return ['unknown message (held for an hour)', null];
        }

        return [TenantContext::current()->run((int) $tenantId, function () use ($provider, $update): ?string {
            $communication = CandidateCommunication::query()
                ->where('provider', $provider)
                ->where('provider_message_id', $update->providerMessageId)
                ->lockForUpdate()
                ->first();

            return $communication === null ? 'unknown message' : $this->applyTo($communication, $provider, $update->status, $update->occurredAt, $update->error);
        }), (int) $tenantId];
    }

    /**
     * Phase 8.7 (DQ-87-08): applies statuses reported before the message id was saved. Called by
     * SendCommunicationJob right after it records the provider's message id.
     */
    public function applyHeld(CandidateCommunication $communication): void
    {
        if ($communication->provider === null || $communication->provider_message_id === null) {
            return;
        }

        $held = Cache::pull($this->heldKey($communication->provider, $communication->provider_message_id), []);

        foreach ($held as $update) {
            DB::transaction(function () use ($communication, $update): void {
                $locked = CandidateCommunication::query()->lockForUpdate()->find($communication->id);

                if ($locked !== null && ($status = CommunicationStatus::tryFrom((string) $update['status'])) !== null) {
                    $this->applyTo($locked, (string) $communication->provider, $status, Carbon::parse($update['occurred_at']), $update['error'] ?? null);
                }
            });
        }
    }

    private function heldKey(string $provider, string $providerMessageId): string
    {
        return 'communications:held-status:'.$provider.':'.sha1($providerMessageId);
    }

    private function applyTo(CandidateCommunication $communication, string $provider, CommunicationStatus $status, CarbonInterface $occurredAt, ?string $error): ?string
    {
        if ($status->rank() <= $communication->status->rank()) {
            return 'stale status';
        }

        $timestamp = match ($status) {
            CommunicationStatus::Sent => 'sent_at',
            CommunicationStatus::Delivered => 'delivered_at',
            CommunicationStatus::Read => 'read_at',
            CommunicationStatus::Failed, CommunicationStatus::Bounced => 'failed_at',
            default => null,
        };

        $communication->forceFill(array_filter([
            'status' => $status,
            $timestamp => $occurredAt,
            'error' => $error,
        ], fn ($value) => $value !== null))->save();

        if (in_array($status, [CommunicationStatus::Failed, CommunicationStatus::Bounced], true)) {
            AuditLog::record($communication, 'communication_failed', null, ['provider' => $provider, 'status' => $status->value, 'error' => $error, 'source' => 'webhook']);
            CommunicationFailed::dispatch($communication);
        }

        return null;
    }

    private function applyOptOut(string $recipient, CommunicationChannel $channel): ?string
    {
        // SaaS-1: an opt-out reply reaches the platform's shared sender and names no tenant, so it
        // applies in every tenant that holds the recipient — each inside its own tenant, audited
        // there. The person's instruction is honoured without reading one tenant from another.
        $query = Candidate::query()->withoutTenancy();
        $tenantIds = ($channel === CommunicationChannel::Email
            ? $query->where('email_normalized', CandidateIdentityNormalizer::email($recipient))
            : $query->where('mobile_normalized', CandidateIdentityNormalizer::mobile($recipient)))
            ->distinct()
            ->pluck('tenant_id');

        if ($tenantIds->isEmpty()) {
            return 'opt-out for unknown recipient';
        }

        foreach ($tenantIds as $tenantId) {
            TenantContext::current()->run((int) $tenantId, function () use ($recipient, $channel): void {
                $candidates = $channel === CommunicationChannel::Email
                    ? Candidate::query()->where('email_normalized', CandidateIdentityNormalizer::email($recipient))->get()
                    : Candidate::query()->where('mobile_normalized', CandidateIdentityNormalizer::mobile($recipient))->get();

                $candidates->each(fn (Candidate $candidate) => $this->preferences->set($candidate, $channel, PreferenceStatus::OptedOut, 'provider_opt_out', reason: 'Opted out by replying to a '.$channel->label().' message'));
            });
        }

        return null;
    }
}
