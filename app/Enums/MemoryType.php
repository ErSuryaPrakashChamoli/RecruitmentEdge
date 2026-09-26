<?php

namespace App\Enums;

/**
 * Kinds of Hiring Memory record. Post-joining outcomes enter only as an accepted, aggregate
 * OutcomePattern (Phase 8.2) — never per person.
 */
enum MemoryType: string
{
    case Hire = 'hire';
    case Rejection = 'rejection';
    case OfferOutcome = 'offer_outcome';
    case JoiningOutcome = 'joining_outcome';
    case RequisitionOutcome = 'requisition_outcome';
    case OutcomePattern = 'outcome_pattern';

    public function label(): string
    {
        return match ($this) {
            self::Hire => 'Hire',
            self::Rejection => 'Rejection',
            self::OfferOutcome => 'Offer outcome',
            self::JoiningOutcome => 'Joining outcome',
            self::RequisitionOutcome => 'Requisition outcome',
            self::OutcomePattern => 'Outcome pattern (aggregate)',
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
            self::OutcomePattern => 'primary',
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
