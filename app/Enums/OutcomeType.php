<?php

namespace App\Enums;

/**
 * The Outcome Loop taxonomy (Phase 8.2) — only outcome types the application has real source data
 * for. Every type is deterministic application logic, never AI. Post-hire categories without a
 * source (performance, attendance, probation, promotion, historical retention) are listed in
 * UNAVAILABLE and always reported as not observed — never fabricated.
 *
 * Retention is observed going forward only: STATUS_OBSERVED_* records the employee status seen on
 * the checkpoint date (medium confidence), or a separation recorded before it. It is not
 * "confirmed retention".
 */
enum OutcomeType: string
{
    case Joined = 'joined';
    case NoShow = 'no_show';
    case Dropout = 'dropout';
    case OfferReleased = 'offer_released';
    case OfferAccepted = 'offer_accepted';
    case OfferRejected = 'offer_rejected';
    case OfferExpired = 'offer_expired';
    case OfferWithdrawn = 'offer_withdrawn';
    case TimeToHire = 'time_to_hire';
    case TimeInStage = 'time_in_stage';
    case SourceToJoin = 'source_to_join';
    case StatusObserved30d = 'status_observed_30d';
    case StatusObserved90d = 'status_observed_90d';
    case StatusObserved180d = 'status_observed_180d';

    /**
     * Post-hire outcomes with no source data today — shown as not observed, never computed.
     *
     * @var array<string, string>
     */
    public const array UNAVAILABLE = [
        'performance' => 'Performance — no performance or appraisal data is recorded.',
        'attendance' => 'Attendance — no attendance data is recorded.',
        'probation' => 'Probation — no probation outcome is recorded.',
        'promotion' => 'Promotion / role change — no history is recorded.',
        'historical_retention' => 'Retention before Phase 8.2 — employee status has no reliable history; retention is observed going forward only.',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Joined => 'Joined',
            self::NoShow => 'No-show',
            self::Dropout => 'Dropped out before joining',
            self::OfferReleased => 'Offer released',
            self::OfferAccepted => 'Offer accepted',
            self::OfferRejected => 'Offer rejected',
            self::OfferExpired => 'Offer expired',
            self::OfferWithdrawn => 'Offer withdrawn',
            self::TimeToHire => 'Time to hire',
            self::TimeInStage => 'Time in stage',
            self::SourceToJoin => 'Source to join',
            self::StatusObserved30d => '30-day status observed',
            self::StatusObserved90d => '90-day status observed',
            self::StatusObserved180d => '180-day status observed',
        };
    }

    public function category(): OutcomeCategory
    {
        return match ($this) {
            self::Joined, self::NoShow, self::Dropout => OutcomeCategory::Joining,
            self::OfferReleased, self::OfferAccepted, self::OfferRejected, self::OfferExpired, self::OfferWithdrawn => OutcomeCategory::Offer,
            self::TimeToHire, self::TimeInStage => OutcomeCategory::Process,
            self::SourceToJoin => OutcomeCategory::Source,
            self::StatusObserved30d, self::StatusObserved90d, self::StatusObserved180d => OutcomeCategory::Retention,
        };
    }

    public function definition(): string
    {
        return match ($this) {
            self::Joined => 'The joining record was marked Joined (source: candidate_joinings; the pipeline stage alone never counts).',
            self::NoShow => 'The joining record was marked No-show.',
            self::Dropout => 'The joining record was marked Dropout before joining.',
            self::OfferReleased, self::OfferAccepted, self::OfferRejected, self::OfferExpired, self::OfferWithdrawn => 'The offer reached this status (source: offer status history). Release does not imply acceptance; acceptance does not imply joining.',
            self::TimeToHire => 'Days from the configured start point (Recruitment Settings: time to hire start point) to the actual joining date.',
            self::TimeInStage => 'Days spent in each pipeline stage, from the immutable stage history, up to joining.',
            self::SourceToJoin => 'Aggregate: completed joins ÷ (joined + no-show + dropout) per candidate source. Not stored per hire.',
            self::StatusObserved30d, self::StatusObserved90d, self::StatusObserved180d => 'The employee status observed on the checkpoint date (medium confidence), or a separation recorded before it (high confidence). Not confirmed retention.',
        };
    }

    public function windowDays(): ?int
    {
        return match ($this) {
            self::StatusObserved30d => 30,
            self::StatusObserved90d => 90,
            self::StatusObserved180d => 180,
            default => null,
        };
    }

    public static function forWindow(int $days): ?self
    {
        return collect(self::cases())->first(fn (self $type) => $type->windowDays() === $days);
    }

    /**
     * @return array<int, self>
     */
    public static function statusObservations(): array
    {
        return [self::StatusObserved30d, self::StatusObserved90d, self::StatusObserved180d];
    }

    public static function forOfferStatus(OfferStatus $status): ?self
    {
        return match ($status) {
            OfferStatus::Released => self::OfferReleased,
            OfferStatus::Accepted => self::OfferAccepted,
            OfferStatus::Rejected => self::OfferRejected,
            OfferStatus::Expired => self::OfferExpired,
            OfferStatus::Withdrawn => self::OfferWithdrawn,
            default => null,
        };
    }

    /**
     * Whether historical records can be reconstructed deterministically. Retention cannot: it is
     * only ever observed going forward.
     */
    public function isBackfillable(): bool
    {
        return $this->category() !== OutcomeCategory::Retention && $this !== self::SourceToJoin;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
