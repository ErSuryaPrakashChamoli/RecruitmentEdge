<?php

namespace App\Services\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The one period primitive every governed metric uses (Phase 8.5 D16/D17/D17a).
 *
 * A period is an inclusive range of calendar dates in the business timezone
 * (config metrics.business_timezone). It is applied to the database driver-independently:
 *
 * - DATE columns (application_date, actual_doj, offer_date, incurred_on …) are compared as
 *   `>= first date` and `< day after the last date`, which is inclusive on MySQL and on SQLite
 *   (where a date cast is stored as "Y-m-d 00:00:00") alike.
 * - Timestamp columns (stored in UTC) are compared against the half-open UTC instants
 *   [first date 00:00, day after the last date 00:00) in the business timezone.
 *
 * Periods built from timestamps: an endpoint that is a calendar-day boundary in its own timezone
 * (00:00:00, or 23:59:59 for an end) names that calendar date; any other instant is converted to
 * the business timezone first. So `now()->startOfMonth()..now()->endOfMonth()` is this calendar
 * month, `Carbon::parse('2026-09-10')` as an end includes the whole of 10 September (the old
 * Copilot "midnight end" defect), and `now()` as an end is today in the business timezone.
 */
final readonly class MetricPeriod
{
    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {
        if ($to->lt($from)) {
            throw new InvalidArgumentException('A metric period cannot end before it starts.');
        }
    }

    public static function timezone(): string
    {
        return (string) config('metrics.business_timezone', 'Asia/Kolkata');
    }

    /**
     * Now, in the business timezone.
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /**
     * The business calendar date an instant falls on.
     */
    public static function businessDate(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::timezone())->toDateString();
    }

    public static function between(CarbonInterface $start, CarbonInterface $end): self
    {
        return self::dates(self::endpointDate($start, isEnd: false), self::endpointDate($end, isEnd: true));
    }

    public static function dates(string $from, string $to): self
    {
        $zone = self::timezone();

        return new self(CarbonImmutable::parse($from, $zone)->startOfDay(), CarbonImmutable::parse($to, $zone)->startOfDay());
    }

    public static function day(?CarbonInterface $day = null): self
    {
        $date = $day !== null ? self::endpointDate($day, isEnd: false) : self::now()->toDateString();

        return self::dates($date, $date);
    }

    /**
     * The last $days calendar days up to and including today.
     */
    public static function lastDays(int $days): self
    {
        $today = self::now()->startOfDay();

        return self::dates($today->subDays(max(1, $days) - 1)->toDateString(), $today->toDateString());
    }

    /**
     * A named preset in the business timezone. Weeks start on Monday (D17a); quarters and years are
     * calendar quarters and years (D17b).
     */
    public static function preset(string $preset, ?string $customFrom = null, ?string $customTo = null): self
    {
        $now = self::now()->startOfDay();

        return match ($preset) {
            'today' => self::dates($now->toDateString(), $now->toDateString()),
            'yesterday' => self::dates($now->subDay()->toDateString(), $now->subDay()->toDateString()),
            'this_week' => self::dates($now->startOfWeek(CarbonInterface::MONDAY)->toDateString(), $now->endOfWeek(CarbonInterface::SUNDAY)->toDateString()),
            'last_month' => self::dates($now->subMonthNoOverflow()->startOfMonth()->toDateString(), $now->subMonthNoOverflow()->endOfMonth()->toDateString()),
            'last_30_days' => self::lastDays(30),
            'this_quarter' => self::dates($now->startOfQuarter()->toDateString(), $now->endOfQuarter()->toDateString()),
            'last_quarter' => self::dates($now->subQuarterNoOverflow()->startOfQuarter()->toDateString(), $now->subQuarterNoOverflow()->endOfQuarter()->toDateString()),
            'this_year' => self::dates($now->startOfYear()->toDateString(), $now->endOfYear()->toDateString()),
            'custom' => self::dates(
                filled($customFrom) ? CarbonImmutable::parse($customFrom)->toDateString() : $now->startOfMonth()->toDateString(),
                filled($customTo) ? CarbonImmutable::parse($customTo)->toDateString() : $now->toDateString(),
            ),
            default => self::dates($now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function presetOptions(): array
    {
        return [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'this_week' => 'This Week',
            'this_month' => 'This Month',
            'last_month' => 'Last Month',
            'last_30_days' => 'Last 30 Days',
            'this_quarter' => 'This Quarter',
            'last_quarter' => 'Last Quarter',
            'this_year' => 'This Year',
            'custom' => 'Custom Range',
        ];
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /**
     * The first calendar date after the period (the exclusive bound for DATE columns).
     */
    public function afterDate(): string
    {
        return $this->to->addDay()->toDateString();
    }

    /**
     * The inclusive start instant, in the application (storage) timezone.
     */
    public function startInstant(): CarbonImmutable
    {
        return $this->from->setTimezone((string) config('app.timezone'));
    }

    /**
     * The exclusive end instant, in the application (storage) timezone.
     */
    public function endInstant(): CarbonImmutable
    {
        return $this->to->addDay()->setTimezone((string) config('app.timezone'));
    }

    /**
     * The last instant inside the period — for callers that still take an inclusive end.
     */
    public function lastInstant(): CarbonImmutable
    {
        return $this->endInstant()->subSecond();
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * The period of equal length immediately before this one.
     */
    public function previous(): self
    {
        $days = $this->days();

        return self::dates($this->from->subDays($days)->toDateString(), $this->from->subDay()->toDateString());
    }

    /**
     * This period cut off at today (for like-for-like comparisons of a period still in progress).
     */
    public function elapsed(): self
    {
        $today = self::now()->startOfDay();

        return $this->to->gt($today) && $this->from->lte($today) ? self::dates($this->fromDate(), $today->toDateString()) : $this;
    }

    /**
     * @template TBuilder of Builder|BuilderContract
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function whereDateColumn(Builder|BuilderContract $query, string $column): Builder|BuilderContract
    {
        return $query->where($column, '>=', $this->fromDate())->where($column, '<', $this->afterDate());
    }

    /**
     * @template TBuilder of Builder|BuilderContract
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function whereTimestampColumn(Builder|BuilderContract $query, string $column): Builder|BuilderContract
    {
        return $query->where($column, '>=', $this->startInstant()->format('Y-m-d H:i:s'))
            ->where($column, '<', $this->endInstant()->format('Y-m-d H:i:s'));
    }

    public function containsInstant(CarbonInterface $instant): bool
    {
        $date = self::businessDate($instant);

        return $date >= $this->fromDate() && $date <= $this->toDate();
    }

    public function containsDate(CarbonInterface|string $date): bool
    {
        $value = $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);

        return $value >= $this->fromDate() && $value <= $this->toDate();
    }

    public function key(): string
    {
        return $this->fromDate().'..'.$this->toDate().'@'.self::timezone();
    }

    public function label(): string
    {
        return $this->fromDate() === $this->toDate()
            ? $this->from->format('d M Y')
            : $this->from->format('d M Y').' – '.$this->to->format('d M Y');
    }

    /**
     * @return array{from: string, to: string, timezone: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->fromDate(), 'to' => $this->toDate(), 'timezone' => self::timezone()];
    }

    private static function endpointDate(CarbonInterface $instant, bool $isEnd): string
    {
        $time = $instant->format('H:i:s');

        if ($time === '00:00:00' || ($isEnd && $time === '23:59:59')) {
            return $instant->toDateString();
        }

        return self::businessDate($instant);
    }
}
