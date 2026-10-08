<?php

namespace App\Enums;

/**
 * Lifecycle of a single AI tool invocation. Read/Recommend tools skip straight to Executed within
 * the same request. Write/External/HighImpact tools stop at Pending until a human with
 * ai.actions.execute approves or rejects via ActionExecutor.
 */
enum AiToolCallStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Executed = 'executed';
    case Failed = 'failed';
    // Phase 8.4: never executed — the approval window passed, or the requester's authority changed.
    case Expired = 'expired';
    case Invalidated = 'invalidated';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Executed => 'Executed',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
            self::Invalidated => 'Invalidated',
        };
    }
}
