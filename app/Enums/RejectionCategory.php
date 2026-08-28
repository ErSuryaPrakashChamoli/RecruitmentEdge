<?php

namespace App\Enums;

/**
 * Lets the rejection-reason dropdown be filtered contextually (e.g. only "offer" reasons when
 * withdrawing an offer) once Interviews/Offers exist in later phases — not enforced yet.
 */
enum RejectionCategory: string
{
    case General = 'general';
    case Interview = 'interview';
    case Offer = 'offer';
    case Joining = 'joining';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Interview => 'Interview',
            self::Offer => 'Offer',
            self::Joining => 'Joining',
        };
    }
}
