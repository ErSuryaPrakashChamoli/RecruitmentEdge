<?php

namespace App\Services\Automation\Actions\Handlers;

use App\Console\Commands\SendCandidateReminders;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationTrigger;
use App\Listeners\SendCandidateCommunications;
use App\Models\AutomationExecution;
use App\Models\CandidateCommunication;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\Actions\Contracts\AutomationAction;
use App\Services\Automation\AutomationContext;
use App\Services\Communication\CommunicationService;

/**
 * Sends a candidate message through CommunicationService — so preferences, consent, the template
 * whitelist, idempotency and the queue all apply exactly as for any other message. Templates the
 * Phase 5 listeners/reminders already send are reserved, and a per-candidate daily cap stops a
 * bad rule from flooding anyone.
 */
class SendCommunicationAction implements AutomationAction
{
    public function __construct(private readonly CommunicationService $communications) {}

    /**
     * @return array<int, string>
     */
    public static function reservedTemplateKeys(): array
    {
        return [...SendCandidateCommunications::TEMPLATE_KEYS, ...SendCandidateReminders::TEMPLATE_KEYS];
    }

    public function key(): string
    {
        return 'send_communication';
    }

    public function label(): string
    {
        return 'Send candidate message';
    }

    public function validate(array $config): array
    {
        $errors = [];
        $key = (string) ($config['template_key'] ?? '');

        if ($key === '') {
            $errors[] = 'Choose the message template to send.';
        } elseif (in_array($key, self::reservedTemplateKeys(), true)) {
            $errors[] = "The \"{$key}\" template is already sent automatically — sending it from a rule would duplicate the message.";
        }

        foreach ((array) ($config['channels'] ?? []) as $channel) {
            if (! in_array(CommunicationChannel::tryFrom((string) $channel), CommunicationChannel::sendable(), true)) {
                $errors[] = "Unknown message channel \"{$channel}\".";
            }
        }

        return $errors;
    }

    public function describe(array $config, ?AutomationContext $context = null): string
    {
        $channels = collect((array) ($config['channels'] ?? []))->map(fn ($channel) => CommunicationChannel::tryFrom((string) $channel)?->label())->filter()->implode(', ');

        return 'Send the "'.($config['template_key'] ?? '?').'" message'.($channels !== '' ? " by {$channels}" : ' on every channel with an active template').' (respecting candidate preferences)';
    }

    public function execute(array $config, AutomationContext $context, AutomationExecution $execution, int $position): ActionOutcome
    {
        $messageContext = $context->messageContext();

        if ($messageContext === null) {
            return ActionOutcome::skipped('No candidate to message.');
        }

        $cap = (int) config('automation.max_candidate_messages_per_day', 3);
        $sentToday = CandidateCommunication::query()
            ->where('candidate_id', $messageContext->candidate->id)
            ->where('trigger', CommunicationTrigger::Automation)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($sentToday >= $cap) {
            return ActionOutcome::skipped("Candidate already received {$sentToday} automated message(s) today (limit {$cap}).");
        }

        $channels = collect((array) ($config['channels'] ?? []))->map(fn ($channel) => CommunicationChannel::tryFrom((string) $channel))->filter()->values()->all();

        $messages = $this->communications->sendAutomatic(
            (string) $config['template_key'],
            $messageContext,
            "automation:{$execution->id}:{$position}",
            $channels === [] ? null : $channels,
            CommunicationTrigger::Automation,
        );

        if ($messages === []) {
            return ActionOutcome::skipped('No active template for the chosen channel(s) — nothing sent.');
        }

        $summary = collect($messages)->map(fn (CandidateCommunication $message) => "{$message->channel->label()}: {$message->status->label()}")->implode(', ');

        return ActionOutcome::completed($summary, $messages[0]);
    }
}
