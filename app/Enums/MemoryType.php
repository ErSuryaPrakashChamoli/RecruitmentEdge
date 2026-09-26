<?php

namespace App\Enums;

/**
 * Kinds of Hiring Memory record. Outcomes after joining (30/60/90/180 days) are Phase 8 and deliberately absent.
 */
enum MemoryType: string
{
    case Hire = 'hire';
    case Rejection = 'rejection';
    case OfferOutcome = 'offer_outcome';
    case JoiningOutcome = 'joining_outcome';
    case RequisitionOutcome = 'requisition_outcome';

    public function label(): string
    {
        return match ($this) {
            self::Hire => 'Hire',
            self::Rejection => 'Rejection',
            self::OfferOutcome => 'Offer outcome',
            self::JoiningOutcome => 'Joining outcome',
            self::RequisitionOutcome => 'Requisition outcome',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Hire => 'success',
            self::Rejection => 'gray',
            self::OfferOutcome => 'warning',
            self::JoiningOutcome => 'danger',
            self::RequisitionOutcome => 'info',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
