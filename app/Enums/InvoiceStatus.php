<?php

namespace App\Enums;

/**
 * SaaS-4: an invoice is Open once issued (numbered, immutable), Paid when its amount is paid in
 * full, Void when withdrawn before any payment. Draft exists only inside the issuing transaction.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Due',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }
}
