<?php

namespace App\Services\Communication;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\CommunicationTrigger;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Jobs\SendCommunicationJob;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateCommunication;
use App\Models\CommunicationTemplate;
use App\Models\Employee;
use App\Services\CandidateIdentityNormalizer;
use App\Services\CandidateTimelineService;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single entry point for sending a candidate a message (Phase 5 Communication Center, event
 * listeners, reminders and approved AI actions all call this). For every message it:
 *
 * 1. resolves the channel and recipient, 2. checks the candidate's preference/consent,
 * 3. checks the provider is configured, 4. validates and renders the template,
 * 5. records the message (queued or blocked) with an idempotency key, 6. records the timeline
 * event and an audit row, 7. queues SendCommunicationJob after commit.
 *
 * A blocked message is recorded, never sent. The same idempotency key never produces a second
 * message — callers pass stable keys for automatic messages (e.g. "interview.scheduled:{id}:email").
 */
class CommunicationService
{
    public function __construct(
        private readonly CommunicationProviderManager $providers,
        private readonly CommunicationPreferenceService $preferences,
        private readonly TemplateRenderer $renderer,
        private readonly CandidateTimelineService $timeline,
        private readonly SendTimeGuard $guard,
    ) {}

    /**
     * Sends $template (or a custom $subject/$body, which may also use {{variables}}) to the
     * candidate on $channel.
     */
    public function send(
        CommunicationChannel $channel,
        MessageContext $context,
        ?CommunicationTemplate $template = null,
        ?string $subject = null,
        ?string $body = null,
        ?Employee $actor = null,
        CommunicationTrigger $trigger = CommunicationTrigger::Manual,
        ?string $idempotencyKey = null,
    ): CandidateCommunication {
        if (! in_array($channel, CommunicationChannel::sendable(), true)) {
            throw new DomainException("{$channel->label()} messages cannot be sent by the platform.");
        }

        if ($template !== null && ($template->channel !== $channel || ! $template->isActive())) {
            throw new DomainException("\"{$template->name}\" is not an active {$channel->label()} template.");
        }

        $derivedKey = $idempotencyKey === null;
        $idempotencyKey ??= $this->deterministicKey($channel, $context, $template, $subject, $body, $actor, $trigger);

        if ($existing = CandidateCommunication::query()->where('idempotency_key', $idempotencyKey)->first()) {
            // A derived key only absorbs a repeat of a message that went out; one that was blocked
            // (no consent yet, no contact) may be tried again once that is fixed.
            if (! $derivedKey || $existing->status !== CommunicationStatus::Blocked) {
                return $existing;
            }

            $idempotencyKey .= ':'.Str::uuid();
        }

        $candidate = $context->candidate;
        $rawSubject = $template?->subject ?? $subject;
        $rawBody = $template?->body ?? (string) $body;

        if (blank($rawBody) || ($channel->supportsSubject() && blank($rawSubject))) {
            throw new DomainException($channel->supportsSubject() ? 'An email needs a subject and a message.' : 'A message is required.');
        }

        $this->renderer->validate((string) $rawSubject, $rawBody);

        $blockedReason = null;
        $renderedSubject = $rawSubject;
        $renderedBody = $rawBody;

        try {
            $renderedSubject = $rawSubject !== null ? $this->renderer->render($rawSubject, $context) : null;
            $renderedBody = $this->renderer->render($rawBody, $context);
        } catch (DomainException $e) {
            if ($trigger === CommunicationTrigger::Manual) {
                throw $e;
            }

            $blockedReason = $e->getMessage();
        }

        // Phase 8.7 (DQ-87-16): a rendered subject can outgrow its column; it is shortened, never
        // allowed to fail the insert.
        $renderedSubject = $renderedSubject !== null ? Str::limit($renderedSubject, 250, '…') : null;

        $recipient = $this->recipientFor($candidate, $channel);
        $provider = $this->providers->for($channel);

        $blockedReason ??= match (true) {
            $recipient === null => "The candidate has no {$channel->label()} contact on file.",
            default => $this->preferences->blockedReason($candidate, $channel),
        };

        $blockedReason ??= $provider === null || ! $provider->isConfigured()
            ? "No {$channel->label()} provider is configured."
            : null;

        $communication = DB::transaction(function () use ($channel, $context, $template, $renderedSubject, $renderedBody, $recipient, $provider, $blockedReason, $actor, $trigger, $idempotencyKey, $candidate): CandidateCommunication {
            $communication = new CandidateCommunication([
                'candidate_id' => $candidate->id,
                'candidate_application_id' => $context->application?->id,
                'requisition_id' => $context->application?->requisition_id,
                'interview_id' => $context->interview?->id,
                'channel' => $channel,
                'direction' => 'outbound',
                'communication_template_id' => $template?->id,
                'template_version' => $template?->version,
                // Phase 8.6 (D8.6-023): the exact version row the message was rendered from.
                'communication_template_version_id' => $template?->currentVersionId(),
                'subject' => $renderedSubject,
                'body' => $renderedBody,
                'recipient' => $recipient ?? '',
                'trigger' => $trigger,
                'idempotency_key' => $idempotencyKey,
                // Phase 8.7 (D8.7-014): the request, command or job that queued this message.
                'origin_request_id' => Context::get('request_id'),
                'sent_by' => $actor?->id,
                'metadata' => array_filter([
                    'provider_template' => $template?->provider_template,
                    'template_parameters' => $template?->provider_template ? $this->safeParameters($template->body, $context) : null,
                    'language' => $template?->language,
                    // Phase 8.7 (D8.7-024): what the message was about when queued; SendTimeGuard
                    // compares it with the current records before sending. Ids and statuses only.
                    'queued_state' => $this->guard->snapshot($context, $template?->key),
                ]),
            ]);
            $communication->forceFill([
                'status' => $blockedReason !== null ? CommunicationStatus::Blocked : CommunicationStatus::Queued,
                'blocked_reason' => $blockedReason !== null ? mb_substr($blockedReason, 0, 250) : null,
                'provider' => $provider?->key(),
                'queued_at' => $blockedReason === null ? now() : null,
            ])->save();

            $this->timeline->record(
                $candidate,
                $channel->timelineType(),
                $blockedReason !== null
                    ? "{$channel->label()} not sent: ".($renderedSubject ?? Str::limit($renderedBody, 60))
                    : "{$channel->label()}: ".($renderedSubject ?? Str::limit($renderedBody, 60)),
                $blockedReason ?? Str::limit($renderedBody, 300),
                match ($trigger) {
                    CommunicationTrigger::Manual => TimelineSource::Recruiter,
                    CommunicationTrigger::Ai => TimelineSource::Ai,
                    default => TimelineSource::System,
                },
                $blockedReason === null ? TimelineVisibility::Candidate : TimelineVisibility::Internal,
                $actor,
                ['application' => $context->application, 'interview' => $context->interview, 'offer' => $context->offer, 'joining' => $context->joining, 'subject' => $communication],
                ['communication' => $communication->public_id, 'channel' => $channel->value, 'trigger' => $trigger->value],
            );

            AuditLog::record($communication, $blockedReason !== null ? 'communication_blocked' : 'communication_queued', null, array_filter([
                'channel' => $channel->value,
                'template' => $template?->key,
                'trigger' => $trigger->value,
                'blocked_reason' => $blockedReason,
            ]));

            return $communication;
        });

        if ($communication->status === CommunicationStatus::Queued) {
            SendCommunicationJob::dispatch($communication->id)->afterCommit();
        }

        return $communication;
    }

    /**
     * Phase 8.7 (D8.7-007 a): an operator sends a failed or bounced message again. The new message
     * carries exactly the stored content (what was approved is what goes), goes to the candidate's
     * current contact details, passes the same consent and send-time checks as any other message,
     * and gets its own key (resend:{original}:{n}) — the original row is never re-used, so the
     * history shows both attempts. Audited with the operator's reason.
     */
    public function resend(CandidateCommunication $original, Employee $actor, string $reason): CandidateCommunication
    {
        if (! in_array($original->status, [CommunicationStatus::Failed, CommunicationStatus::Bounced], true)) {
            throw new DomainException('Only a failed or bounced message can be resent.');
        }

        if (blank(trim($reason))) {
            throw new DomainException('A reason is required to resend a message.');
        }

        $original->loadMissing('candidate');
        $resendPrefix = "resend:{$original->id}:";

        if (CandidateCommunication::query()->where('idempotency_key', 'like', $resendPrefix.'%')->whereIn('status', [CommunicationStatus::Queued, CommunicationStatus::Sending])->exists()) {
            throw new DomainException('A resend of this message is already waiting to be sent.');
        }

        $recipient = $this->recipientFor($original->candidate, $original->channel);
        $provider = $this->providers->for($original->channel);

        $blockedReason = match (true) {
            $recipient === null => "The candidate has no {$original->channel->label()} contact on file.",
            default => $this->guard->suppressionReason($original),
        };

        $blockedReason ??= $provider === null || ! $provider->isConfigured()
            ? "No {$original->channel->label()} provider is configured."
            : null;

        $sequence = CandidateCommunication::query()->where('idempotency_key', 'like', $resendPrefix.'%')->count() + 1;

        $communication = DB::transaction(function () use ($original, $actor, $reason, $recipient, $provider, $blockedReason, $resendPrefix, $sequence): CandidateCommunication {
            $communication = new CandidateCommunication([
                ...$original->only(['candidate_id', 'candidate_application_id', 'requisition_id', 'interview_id', 'channel', 'direction', 'communication_template_id', 'template_version', 'communication_template_version_id', 'subject', 'body']),
                'recipient' => $recipient ?? '',
                'trigger' => CommunicationTrigger::Manual,
                'idempotency_key' => $resendPrefix.$sequence,
                'origin_request_id' => Context::get('request_id'),
                'sent_by' => $actor->id,
                'metadata' => [...($original->metadata ?? []), 'resent_from' => $original->public_id],
            ]);
            $communication->forceFill([
                'status' => $blockedReason !== null ? CommunicationStatus::Blocked : CommunicationStatus::Queued,
                'blocked_reason' => $blockedReason !== null ? mb_substr($blockedReason, 0, 250) : null,
                'provider' => $provider?->key(),
                'queued_at' => $blockedReason === null ? now() : null,
            ])->save();

            AuditLog::record($communication, 'communication_resent', null, array_filter([
                'resent_from' => $original->public_id,
                'channel' => $original->channel->value,
                'blocked_reason' => $blockedReason,
            ]), $reason);

            return $communication;
        });

        if ($communication->status === CommunicationStatus::Queued) {
            SendCommunicationJob::dispatch($communication->id)->afterCommit();
        }

        return $communication;
    }

    /**
     * Automatic communications: sends the active template for $key on each of $channels. Channels
     * without an active template are skipped silently (not every organisation automates every
     * message). Returns the messages created.
     *
     * @param  array<int, CommunicationChannel>|null  $channels
     * @return array<int, CandidateCommunication>
     */
    public function sendAutomatic(string $key, MessageContext $context, string $idempotencyBase, ?array $channels = null, CommunicationTrigger $trigger = CommunicationTrigger::Event): array
    {
        $sent = [];

        foreach ($channels ?? CommunicationChannel::sendable() as $channel) {
            $template = CommunicationTemplate::activeFor($key, $channel);

            if ($template === null) {
                continue;
            }

            try {
                $sent[] = $this->send($channel, $context, $template, trigger: $trigger, idempotencyKey: "{$idempotencyBase}:{$channel->value}");
            } catch (UniqueConstraintViolationException) {
                // Phase 8.7: a concurrent send of the same message won the idempotency key — it is
                // already queued; nothing to report (the SQL would repeat the message content).
                continue;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * Preview of what $template would send for $context (no side effects).
     *
     * @return array{subject: string|null, body: string}
     */
    public function preview(CommunicationTemplate $template, ?MessageContext $context = null): array
    {
        if ($context === null) {
            return ['subject' => $template->subject !== null ? $this->renderer->preview($template->subject) : null, 'body' => $this->renderer->preview($template->body)];
        }

        return [
            'subject' => $template->subject !== null ? $this->renderer->render($template->subject, $context) : null,
            'body' => $this->renderer->render($template->body, $context),
        ];
    }

    public function recipientFor(Candidate $candidate, CommunicationChannel $channel): ?string
    {
        if ($channel === CommunicationChannel::Email) {
            return filled($candidate->email) && filter_var($candidate->email, FILTER_VALIDATE_EMAIL) ? $candidate->email : null;
        }

        $digits = CandidateIdentityNormalizer::mobile($candidate->mobile);

        if ($digits === null || strlen($digits) < 8) {
            return null;
        }

        return '+'.(strlen($digits) === 10 ? config('communications.default_country_code').$digits : $digits);
    }

    /**
     * Phase 8.7 (D8.7-005): a manual or AI send without a caller key gets one derived from who is
     * sending what to whom, within a five-minute window — so a double-submitted form or a replayed
     * AI tool call queues the message once. Other triggers always pass their own stable keys.
     */
    private function deterministicKey(CommunicationChannel $channel, MessageContext $context, ?CommunicationTemplate $template, ?string $subject, ?string $body, ?Employee $actor, CommunicationTrigger $trigger): string
    {
        if (! in_array($trigger, [CommunicationTrigger::Manual, CommunicationTrigger::Ai], true)) {
            return $trigger->value.':'.Str::uuid();
        }

        $fingerprint = sha1(implode('|', [$actor?->id, $context->candidate->id, $context->application?->id, $channel->value, $template?->id, $subject, $body]));

        return $trigger->value.':'.$fingerprint.':'.intdiv(now()->getTimestamp(), 300);
    }

    /**
     * @return array<int, string>
     */
    private function safeParameters(string $body, MessageContext $context): array
    {
        try {
            return $this->renderer->parameters($body, $context);
        } catch (Throwable) {
            return [];
        }
    }
}
