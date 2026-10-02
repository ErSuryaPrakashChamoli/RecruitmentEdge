<?php

namespace App\Services\Communication;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\OfferStatus;
use App\Enums\TemplateStatus;
use App\Models\CandidateCommunication;
use App\Models\Offer;
use App\Models\User;

/**
 * Phase 8.7 (D8.7-024, D8.7-008 c): what must stop a message that is already queued. Content is
 * rendered at queue time and stays authoritative; what can change before the worker sends it is
 * the world the message describes. snapshot() records that world when the message is queued;
 * suppressionReason() compares it with the current records at send time.
 *
 * A message is suppressed (Blocked with a reason, audited as communication_suppressed — never a
 * provider failure) when:
 *
 * 1. the candidate opted out of, or has not consented to, the channel — always;
 * 2. the application was open when queued and has since closed (rejected or dropped out);
 * 3. the interview it is about was cancelled, completed, marked no-show or moved to another time
 *    (the cancellation notice itself is exempt — it needs the interview cancelled);
 * 4. the offer it is about was withdrawn, expired, declined or sent back to draft;
 * 5. the candidate joined after it was queued and it is a recruitment-stage template (joining and
 *    onboarding templates still go);
 * 6. its template was archived after it was queued (archiving is the "stop sending" switch);
 * 7. (Phase 8.9, P89-SEC-011) a staff member sent it — by hand, a resend or through the Copilot —
 *    and no longer has the authority to: their access was suspended or revoked, they lost
 *    communications.send, or the candidate left their hierarchy. Automated messages carry no
 *    sender; their rule owner is re-checked when the automation runs.
 *
 * Only changes after queueing count: a rejection notice queued for a rejected application is sent.
 */
class SendTimeGuard
{
    /**
     * Template keys that still make sense once the candidate has joined.
     *
     * @var array<int, string>
     */
    public const array POST_HIRE_KEY_PREFIXES = ['joining', 'onboarding'];

    public function __construct(private readonly CommunicationPreferenceService $preferences) {}

    /**
     * What the message was about when it was queued. Ids and statuses only.
     *
     * @return array<string, int|string|null>
     */
    public function snapshot(MessageContext $context, ?string $templateKey): array
    {
        return [
            'template_key' => $templateKey,
            'application_status' => $context->application?->status?->value,
            'application_stage' => $context->application?->current_stage?->value,
            'interview_status' => $context->interview?->status?->value,
            'interview_at' => $context->interview?->scheduled_at?->getTimestamp(),
            'offer_id' => $context->offer?->id,
            'offer_status' => $context->offer?->status?->value,
        ];
    }

    /**
     * Why $communication must not be sent now, or null when it may.
     */
    public function suppressionReason(CandidateCommunication $communication): ?string
    {
        $communication->loadMissing(['candidate', 'candidateApplication', 'interview', 'template']);
        $queued = $communication->metadata['queued_state'] ?? [];

        if ($communication->candidate === null) {
            return 'The candidate record no longer exists.';
        }

        if ($reason = $this->preferences->blockedReason($communication->candidate, $communication->channel)) {
            return $reason;
        }

        return $this->senderReason($communication)
            ?? $this->applicationReason($communication, $queued)
            ?? $this->interviewReason($communication, $queued)
            ?? $this->offerReason($queued)
            ?? $this->hiredReason($communication, $queued)
            ?? $this->templateReason($communication);
    }

    /**
     * The staff sender must still be allowed to send this message now: an active login (the gate is
     * fail-closed for suspended and revoked users), communications.send, and the candidate visible to
     * them — exactly what the send action required when the message was queued.
     */
    private function senderReason(CandidateCommunication $communication): ?string
    {
        if ($communication->sent_by === null) {
            return null;
        }

        $sender = User::query()->where('employee_id', $communication->sent_by)->first();

        if ($sender === null || ! $sender->can('communications.send') || ! $sender->can('view', $communication->candidate)) {
            return 'The staff member who sent this message no longer has access to send it.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $queued
     */
    private function applicationReason(CandidateCommunication $communication, array $queued): ?string
    {
        $application = $communication->candidateApplication;
        $closed = [ApplicationStatus::Rejected, ApplicationStatus::Dropout];

        if ($application === null || ! in_array($application->status, $closed, true)) {
            return null;
        }

        $wasClosed = in_array(ApplicationStatus::tryFrom((string) ($queued['application_status'] ?? '')), $closed, true);

        return $wasClosed ? null : "The application was closed ({$application->status->label()}) after the message was queued.";
    }

    /**
     * @param  array<string, mixed>  $queued
     */
    private function interviewReason(CandidateCommunication $communication, array $queued): ?string
    {
        $interview = $communication->interview;

        if ($interview === null) {
            return $communication->interview_id !== null ? 'The interview no longer exists.' : null;
        }

        if (($queued['template_key'] ?? null) === 'interview_cancelled') {
            return $interview->status === InterviewStatus::Cancelled ? null : 'The interview is no longer cancelled.';
        }

        if (in_array($interview->status, [InterviewStatus::Cancelled, InterviewStatus::Completed, InterviewStatus::NoShow], true)
            && $interview->status->value !== ($queued['interview_status'] ?? null)) {
            return "The interview is now {$interview->status->label()}.";
        }

        if (isset($queued['interview_at']) && $interview->scheduled_at?->getTimestamp() !== (int) $queued['interview_at']) {
            return 'The interview was moved to another time after the message was queued.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $queued
     */
    private function offerReason(array $queued): ?string
    {
        if (! isset($queued['offer_id'])) {
            return null;
        }

        $offer = Offer::query()->find($queued['offer_id']);

        if ($offer === null) {
            return 'The offer no longer exists.';
        }

        $noLongerCurrent = [OfferStatus::Withdrawn, OfferStatus::Expired, OfferStatus::Rejected, OfferStatus::Draft, OfferStatus::Initiated];

        return in_array($offer->status, $noLongerCurrent, true) && $offer->status->value !== ($queued['offer_status'] ?? null)
            ? "The offer is now {$offer->status->label()}."
            : null;
    }

    /**
     * @param  array<string, mixed>  $queued
     */
    private function hiredReason(CandidateCommunication $communication, array $queued): ?string
    {
        $key = $queued['template_key'] ?? null;
        $stage = $communication->candidateApplication?->current_stage;

        if ($key === null || $stage === null || collect(self::POST_HIRE_KEY_PREFIXES)->contains(fn (string $prefix): bool => str_starts_with($key, $prefix))) {
            return null;
        }

        $joined = CandidateStage::Joined->order();
        $wasJoined = ($queuedStage = CandidateStage::tryFrom((string) ($queued['application_stage'] ?? ''))) !== null && $queuedStage->order() >= $joined;

        return $stage->order() >= $joined && ! $wasJoined ? 'The candidate has joined; recruitment messages are no longer sent.' : null;
    }

    private function templateReason(CandidateCommunication $communication): ?string
    {
        return $communication->template?->status === TemplateStatus::Archived
            ? "The template \"{$communication->template->name}\" was archived after the message was queued."
            : null;
    }
}
