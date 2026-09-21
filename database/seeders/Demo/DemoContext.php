<?php

namespace Database\Seeders\Demo;

use App\Models\CandidateSource;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Location;
use App\Models\RecruitmentRejectionReason;
use App\Services\SequenceCodeGenerator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use LogicException;

/**
 * Shared state for one demo seeding run: the seeded random generator, the story's time window, the
 * cast of people and the reference data every part of the story looks up.
 */
final class DemoContext
{
    public readonly Generator $faker;

    /**
     * The real "now" when seeding started. Nothing in the story happens after it.
     */
    public readonly CarbonImmutable $today;

    /**
     * About six months back: the earliest recruitment activity in the story.
     */
    public readonly CarbonImmutable $storyStart;

    /**
     * @var array<string, Employee>
     */
    public array $people = [];

    /**
     * @var array<string, Location>
     */
    public array $locations = [];

    /**
     * @var array<string, Department>
     */
    public array $departments = [];

    /**
     * @var array<string, Designation>
     */
    public array $designations = [];

    /**
     * @var array<string, CandidateSource>
     */
    public array $sources = [];

    /**
     * @var array<string, RecruitmentRejectionReason>
     */
    public array $reasons = [];

    /**
     * Each recruiter's candidates, in the order they were sourced: employee id => list of [candidate id, sourced timestamp].
     *
     * @var array<int, list<array{0: int, 1: int}>>
     */
    public array $candidatesByRecruiter = [];

    /**
     * @var array<string, true>
     */
    private array $usedMobiles = [];

    /**
     * @var array<string, true>
     */
    private array $usedEmails = [];

    public function __construct(public readonly float $scale, int $seed)
    {
        $this->faker = Factory::create('en_IN');
        $this->faker->seed($seed);

        $this->today = CarbonImmutable::now();
        $this->storyStart = $this->today->subDays(180)->startOfDay();
    }

    /**
     * Freezes both the mutable and the immutable Carbon clocks at $at (null releases them).
     */
    public static function freeze(?CarbonInterface $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    /**
     * The story's current moment.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    public function email(string $name): string
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'candidate');

        do {
            $email = Str::slug($first, '').'.'.Str::slug($last, '').$this->number(1, 999).'@example.com';
        } while (isset($this->usedEmails[$email]));

        $this->usedEmails[$email] = true;

        return $email;
    }

    public function person(string $key): Employee
    {
        return $this->people[$key] ?? throw new LogicException("Unknown demo person [{$key}].");
    }

    public function reason(string $name): RecruitmentRejectionReason
    {
        return $this->reasons[$name] ?? throw new LogicException("Unknown rejection reason [{$name}].");
    }

    public function source(string $name): CandidateSource
    {
        return $this->sources[$name] ?? throw new LogicException("Unknown candidate source [{$name}].");
    }

    /**
     * Makes the given employee's login the authenticated user, so audit logs and permission-checked
     * service calls are attributed to the person doing the work.
     */
    public function actAs(?Employee $employee): void
    {
        $user = $employee?->user;

        if ($user !== null) {
            Auth::guard('web')->setUser($user);

            return;
        }

        Auth::guard('web')->forgetUser();
    }

    public function code(string $prefix): string
    {
        return app(SequenceCodeGenerator::class)->next($prefix);
    }

    public function scaled(int|float $count): int
    {
        return max(0, (int) round($count * $this->scale));
    }

    public function number(int $min, int $max): int
    {
        return $this->faker->numberBetween($min, $max);
    }

    public function chance(float $probability): bool
    {
        return $this->faker->numberBetween(1, 10000) <= (int) round($probability * 10000);
    }

    /**
     * @template T
     *
     * @param  array<int|string, T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $this->faker->randomElement($items);
    }

    /**
     * @param  array<string, int>  $weights
     */
    public function weighted(array $weights): string
    {
        $roll = $this->number(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_last($weights);
    }

    /**
     * A moment between $minMinutes and $maxMinutes after $from, moved into office hours.
     */
    public function later(CarbonInterface $from, int $minMinutes, int $maxMinutes): CarbonImmutable
    {
        return $this->officeHours(CarbonImmutable::instance($from)->addMinutes($this->number($minMinutes, $maxMinutes)));
    }

    /**
     * Moves a moment into Monday–Saturday, 09:30–19:00 office hours.
     */
    public function officeHours(CarbonImmutable $at): CarbonImmutable
    {
        if ($at->hour >= 19) {
            $at = $at->addDay()->setTime(10, $this->number(0, 59));
        } elseif ($at->hour < 9 || ($at->hour === 9 && $at->minute < 30)) {
            $at = $at->setTime(10, $this->number(0, 59));
        }

        return $at->isSunday() ? $at->addDay() : $at;
    }

    /**
     * An interview slot on the given day (moved off Sundays).
     */
    public function slot(CarbonImmutable $day): CarbonImmutable
    {
        $day = $day->isSunday() ? $day->addDay() : $day;

        return $day->setTime($this->pick([10, 11, 12, 14, 15, 16, 17]), $this->pick([0, 30]));
    }

    public function mobile(): string
    {
        do {
            $mobile = $this->pick(['9', '8', '7', '6']).$this->faker->numerify('#########');
        } while (isset($this->usedMobiles[$mobile]));

        $this->usedMobiles[$mobile] = true;

        return $mobile;
    }

    public function personName(): string
    {
        return $this->pick(DemoCatalog::FIRST_NAMES).' '.$this->pick(DemoCatalog::LAST_NAMES);
    }

    /**
     * A salary-like amount in [$min, $max], rounded to the nearest thousand.
     */
    public function amount(int|float $min, int|float $max): int
    {
        return (int) (round($this->number((int) $min, (int) $max) / 1000) * 1000);
    }
}
