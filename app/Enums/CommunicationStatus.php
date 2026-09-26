<?php

namespace App\Enums;

/**
 * Lifecycle of one outbound message. `Sent` means handed to the provider; only a provider (a
 * delivery webhook or status call) can move a message to Delivered/Read/Bounced — the platform
 * never infers delivery. Opened/clicked are recorded as timestamps when a provider reports them.
 */
enum CommunicationStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Read => 'Read',
            self::Failed => 'Failed',
            self::Bounced => 'Bounced',
            self::Blocked => 'Blocked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued, self::Sending => 'gray',
            self::Sent => 'info',
            self::Delivered, self::Read => 'success',
            self::Failed, self::Bounced => 'danger',
            self::Blocked => 'warning',
        };
    }

    /**
     * Provider-reported progress order, so an out-of-order webhook (e.g. "sent" after "read")
     * never moves a message backwards.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Blocked => 0,
            self::Queued => 1,
            self::Sending => 2,
            self::Sent => 3,
            self::Failed, self::Bounced => 4,
            self::Delivered => 5,
            self::Read => 6,
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Read, self::Failed, self::Bounced, self::Blocked], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
