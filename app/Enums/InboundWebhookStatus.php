<?php

namespace App\Enums;

/**
 * SaaS-6: a verified inbound webhook delivery. Received → Processing (claimed) → Processed, or
 * Failed after its attempts (reprocessable by an administrator), or Ignored (nothing to do).
 */
enum InboundWebhookStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Processed => 'success',
            self::Failed => 'danger',
            self::Ignored => 'gray',
            self::Received, self::Processing => 'info',
        };
    }
}
