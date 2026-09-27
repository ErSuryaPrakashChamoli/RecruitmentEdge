<?php

namespace App\Jobs;

use App\Enums\CommunicationStatus;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Events\CommunicationFailed;
use App\Models\AuditLog;
use App\Models\CandidateCommunication;
use App\Services\CandidateTimelineService;
use App\Services\Communication\CommunicationProviderManager;
use App\Services\Communication\Data\OutboundMessage;
use App\Services\Communication\ProviderCircuitBreaker;
use App\Services\Communication\SendTimeGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Hands one queued candidate message to its provider (Phase 5). Idempotent and duplicate-safe:
 *
 * - the message is claimed under a row lock (Queued → Sending) before any provider call, so two
 *   workers can never send it twice;
 * - a message found already Sending (a worker died after the provider call but before recording
 *   the result) is NOT resent — its delivery state is unknown, so it is marked Failed for a human
 *   to check rather than risk a duplicate to the candidate;
 * - temporary provider failures return it to Queued and retry with backoff; permanent ones fail.
 *
 * Phase 8.7:
 * - at claim time the message is re-checked against the world it describes (SendTimeGuard:
 *   consent, closed application, interview or offer no longer current, candidate joined, template
 *   archived). A message that no longer applies is suppressed — Blocked with a reason and audited
 *   as communication_suppressed. That is a policy outcome, not a provider failure;
 * - while its provider's circuit is open (ProviderCircuitBreaker) the message stays Queued without
 *   using an attempt; reliability:sweep re-queues it when the provider is back;
 * - the row records whether the accepting provider delivers outside this server.
 */
class SendCommunicationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $communicationId)
    {
        $this->tries = (int) config('communications.tries', 5);
        $this->onQueue(config('communications.queue', 'communications'));
    }

    public function uniqueId(): string
    {
        return (string) $this->communicationId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('communications.backoff', [30, 120, 600, 1800]);
    }

    public function handle(CommunicationProviderManager $providers, SendTimeGuard $guard, ProviderCircuitBreaker $circuit, CandidateTimelineService $timeline): void
    {
        $pending = CandidateCommunication::query()->find($this->communicationId);

        if ($pending === null) {
            return;
        }

        if ($pending->status === CommunicationStatus::Queued && $pending->provider !== null && $circuit->isOpen($pending->provider)) {
            Log::info('communications.held_while_provider_paused', ['communication_id' => $pending->id, 'provider' => $pending->provider]);

            return;
        }

        $communication = $this->claim($guard, $timeline);

        if ($communication === null) {
            return;
        }

        $provider = $providers->find((string) $communication->provider);

        if ($provider === null || ! $provider->isConfigured()) {
            $this->markFailed($communication, 'Provider '.($communication->provider ?? 'none').' is no longer configured.');

            return;
        }

        $result = $provider->send(new OutboundMessage(
            channel: $communication->channel,
            recipient: $communication->recipient,
            body: $communication->body,
            reference: $communication->public_id,
            subject: $communication->subject,
            providerTemplate: $communication->metadata['provider_template'] ?? null,
            templateParameters: $communication->metadata['template_parameters'] ?? [],
            language: $communication->metadata['language'] ?? 'en',
        ));

        if ($result->accepted) {
            $circuit->recordSuccess($provider->key());
            $communication->forceFill([
                'status' => CommunicationStatus::Sent,
                'provider_message_id' => $result->providerMessageId,
                'delivered_externally' => $provider->deliversExternally(),
                'sent_at' => now(),
                'error' => null,
            ])->save();

            AuditLog::record($communication, 'communication_sent', null, ['provider' => $provider->key(), 'provider_message_id' => $result->providerMessageId, 'external_delivery' => $provider->deliversExternally()]);

            return;
        }

        if ($result->retryable && $circuit->recordFailure($provider->key())) {
            // This failure paused the provider: hold the message rather than spend its retries.
            $communication->forceFill(['status' => CommunicationStatus::Queued, 'error' => $result->error])->save();

            return;
        }

        if ($result->retryable && $this->attempts() < $this->tries) {
            $communication->forceFill(['status' => CommunicationStatus::Queued, 'error' => $result->error])->save();

            throw new RuntimeException('Temporary provider failure for communication '.$communication->id.': '.$result->error);
        }

        $this->markFailed($communication, (string) $result->error);
    }

    /**
     * Retries exhausted by a thrown exception.
     */
    public function failed(?Throwable $exception): void
    {
        $communication = CandidateCommunication::query()->find($this->communicationId);

        if ($communication !== null && ! in_array($communication->status, [CommunicationStatus::Sent, CommunicationStatus::Delivered, CommunicationStatus::Read, CommunicationStatus::Failed], true)) {
            $this->markFailed($communication, 'Retries exhausted: '.($communication->error ?? $exception?->getMessage() ?? 'unknown error'));
        }
    }

    private function claim(SendTimeGuard $guard, CandidateTimelineService $timeline): ?CandidateCommunication
    {
        return DB::transaction(function () use ($guard, $timeline): ?CandidateCommunication {
            $communication = CandidateCommunication::query()->lockForUpdate()->find($this->communicationId);

            if ($communication === null) {
                return null;
            }

            if ($communication->status === CommunicationStatus::Sending) {
                $this->markFailed($communication, 'Delivery state unknown after an interrupted send — not retried to avoid a duplicate message. Check with the provider before resending.');

                return null;
            }

            if ($communication->status !== CommunicationStatus::Queued) {
                return null;
            }

            if ($reason = $guard->suppressionReason($communication)) {
                $this->suppress($communication, $reason, $timeline);

                return null;
            }

            $communication->forceFill(['status' => CommunicationStatus::Sending, 'attempts' => $communication->attempts + 1])->save();

            return $communication;
        });
    }

    private function suppress(CandidateCommunication $communication, string $reason, CandidateTimelineService $timeline): void
    {
        $communication->forceFill(['status' => CommunicationStatus::Blocked, 'blocked_reason' => mb_substr($reason, 0, 250)])->save();

        AuditLog::record($communication, 'communication_suppressed', null, ['channel' => $communication->channel->value, 'reason' => $reason]);

        $timeline->record(
            $communication->candidate_id,
            $communication->channel->timelineType(),
            "{$communication->channel->label()} not sent: ".($communication->subject ?? Str::limit($communication->body, 60)),
            $reason,
            TimelineSource::System,
            TimelineVisibility::Internal,
            related: ['application' => $communication->candidateApplication, 'interview' => $communication->interview, 'subject' => $communication],
            metadata: ['communication' => $communication->public_id, 'channel' => $communication->channel->value, 'suppressed' => true],
        );
    }

    private function markFailed(CandidateCommunication $communication, string $error): void
    {
        $communication->forceFill(['status' => CommunicationStatus::Failed, 'error' => mb_substr($error, 0, 1000), 'failed_at' => now()])->save();

        AuditLog::record($communication, 'communication_failed', null, ['provider' => $communication->provider, 'error' => mb_substr($error, 0, 500)]);
        CommunicationFailed::dispatch($communication);
        Log::warning('Candidate communication failed', ['communication_id' => $communication->id, 'provider' => $communication->provider, 'channel' => $communication->channel->value]);
    }
}
