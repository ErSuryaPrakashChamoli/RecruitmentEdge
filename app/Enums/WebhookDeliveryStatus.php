<?php

namespace App\Enums;

/**
 * SaaS-6: an outbound delivery. Pending → Sending (claimed by one worker) → Succeeded, or Retrying
 * (scheduled again) → … → Failed once the retry schedule is spent. A replay sets it Pending again.
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Retrying = 'retrying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Retrying => 'warning',
            self::Pending, self::Sending => 'info',
        };
    }
}
