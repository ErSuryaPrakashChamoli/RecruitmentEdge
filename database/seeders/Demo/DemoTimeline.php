<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Support\Carbon;
use LogicException;
use SplPriorityQueue;

/**
 * Plays the demo story in chronological order. Every process — a candidate's journey, a
 * requisition's lifecycle, a monthly payroll cycle — is a generator that yields the moment it acts
 * next; the timeline freezes the clock at that moment (Carbon::setTestNow) and resumes it. So every
 * row the real services write (stage history, offer history, notifications, audit logs) carries a
 * realistic timestamp, and anything due after "today" never runs — which is exactly what leaves the
 * live pipeline in flight.
 */
class DemoTimeline
{
    /**
     * @var SplPriorityQueue<array{0: int, 1: int}, Generator>
     */
    private SplPriorityQueue $queue;

    private int $sequence = 0;

    private ?CarbonImmutable $clock = null;

    public function __construct(private readonly CarbonImmutable $until)
    {
        $this->queue = new SplPriorityQueue;
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
    }

    /**
     * @param  Generator<int, CarbonInterface, mixed, void>  $process
     */
    public function add(Generator $process): void
    {
        $this->enqueue($process);
    }

    public function run(): void
    {
        try {
            while (! $this->queue->isEmpty()) {
                /** @var Generator<int, CarbonInterface, mixed, void> $process */
                $process = $this->queue->extract();

                $this->clock = $this->momentOf($process);
                DemoContext::freeze($this->clock);

                $process->next();
                $this->enqueue($process);
            }
        } finally {
            DemoContext::freeze(null);
        }
    }

    /**
     * @param  Generator<int, CarbonInterface, mixed, void>  $process
     */
    private function enqueue(Generator $process): void
    {
        if (! $process->valid()) {
            return;
        }

        $at = $this->momentOf($process);

        if ($at->greaterThan($this->until)) {
            return;
        }

        // Earliest first; ties keep insertion order so every run is reproducible.
        $this->queue->insert($process, [-$at->getTimestamp(), -$this->sequence++]);
    }

    /**
     * @param  Generator<int, CarbonInterface, mixed, void>  $process
     */
    private function momentOf(Generator $process): CarbonImmutable
    {
        $at = $process->current();

        if (! $at instanceof CarbonInterface) {
            throw new LogicException('Demo timeline processes must yield the moment they act next.');
        }

        $at = CarbonImmutable::instance($at);

        // A process can never act before the story's current moment.
        return $this->clock !== null && $at->lessThan($this->clock) ? $this->clock : $at;
    }
}
