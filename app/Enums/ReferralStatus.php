<?php

namespace App\Enums;

/**
 * Employee referral lifecycle (Phase 4). Review statuses (Under Review, Accepted, Rejected, Closed)
 * are set by reviewers through ReferralService::moveTo(); pipeline statuses (In Process onwards)
 * follow the linked application automatically via ReferralService::syncFromApplication().
 */
enum ReferralStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case InProcess = 'in_process';
    case Selected = 'selected';
    case OfferReleased = 'offer_released';
    case Joined = 'joined';
    case DidNotJoin = 'did_not_join';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under Review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::InProcess => 'In Process',
            self::Selected => 'Selected',
            self::OfferReleased => 'Offer Released',
            self::Joined => 'Joined',
            self::DidNotJoin => 'Did Not Join',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted, self::UnderReview => 'gray',
            self::Accepted, self::InProcess => 'info',
            self::Selected, self::OfferReleased => 'warning',
            self::Joined => 'success',
            self::Rejected, self::DidNotJoin => 'danger',
            self::Closed => 'gray',
        };
    }

    /**
     * Statuses a reviewer may move to by hand from this one.
     *
     * @return array<int, self>
     */
    public function manualTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::Accepted, self::Rejected, self::Closed],
            self::UnderReview => [self::Accepted, self::Rejected, self::Closed],
            self::Accepted => [self::Rejected, self::Closed],
            self::InProcess, self::Selected, self::OfferReleased => [self::Closed],
            self::Joined, self::Rejected, self::DidNotJoin => [self::Closed],
            self::Closed => [],
        };
    }

    /**
     * Progress order for pipeline-driven statuses — sync only ever moves forward along this.
     */
    public function progress(): int
    {
        return match ($this) {
            self::Submitted => 0,
            self::UnderReview => 1,
            self::Accepted => 2,
            self::InProcess => 3,
            self::Selected => 4,
            self::OfferReleased => 5,
            self::Joined => 6,
            self::Rejected, self::DidNotJoin, self::Closed => 7,
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Rejected, self::DidNotJoin, self::Closed], true);
    }

    /**
     * @return array<int, self>
     */
    public static function inProcess(): array
    {
        return [self::Accepted, self::InProcess, self::Selected, self::OfferReleased];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
