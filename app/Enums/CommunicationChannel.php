<?php

namespace App\Enums;

/**
 * Candidate communication channels (Phase 5). Phone is a preference-only channel — calls are
 * logged as structured activities (recruitment_daily_activities), never sent by the platform.
 */
enum CommunicationChannel: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
            self::Phone => 'Phone',
        };
    }

    /**
     * Channels the platform can send messages on (Phone is preference-only).
     *
     * @return array<int, self>
     */
    public static function sendable(): array
    {
        return [self::Email, self::WhatsApp, self::Sms];
    }

    /**
     * WhatsApp needs explicit opt-in consent (provider policy); email and SMS are allowed for
     * transactional recruitment messages unless the candidate opted out.
     */
    public function requiresExplicitConsent(): bool
    {
        return $this === self::WhatsApp;
    }

    public function timelineType(): TimelineEventType
    {
        return match ($this) {
            self::Email => TimelineEventType::Email,
            self::WhatsApp => TimelineEventType::WhatsApp,
            self::Sms => TimelineEventType::Sms,
            self::Phone => TimelineEventType::Call,
        };
    }

    public function supportsSubject(): bool
    {
        return $this === self::Email;
    }

    /**
     * @return array<string, string>
     */
    public static function options(bool $sendableOnly = false): array
    {
        return collect($sendableOnly ? self::sendable() : self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
