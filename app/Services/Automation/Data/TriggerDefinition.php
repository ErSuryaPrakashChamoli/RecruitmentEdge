<?php

namespace App\Services\Automation\Data;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One automation trigger. `event` triggers fire from a real domain event (after commit);
 * `schedule` triggers are found by the recruitment:automation:dispatch sweep with a targeted,
 * indexed query built by `sweep` for the rule's threshold.
 */
final readonly class TriggerDefinition
{
    /**
     * @param  class-string<Model>  $subjectClass
     * @param  class-string|null  $eventClass
     * @param  array<string, string>  $anchors  Date fields a delayed event rule can be timed against
     * @param  (Closure(CarbonInterface $threshold): Builder<Model>)|null  $sweep
     * @param  (Closure(Model): (CarbonInterface|null))|null  $anchor  The date that re-arms a schedule trigger when it changes
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $group,
        public string $kind,
        public string $subjectClass,
        public string $description,
        public ?string $eventClass = null,
        public array $anchors = [],
        public ?Closure $sweep = null,
        public ?Closure $anchor = null,
        public ?string $thresholdLabel = null,
        public bool $thresholdIsLookahead = false,
    ) {}

    public function isScheduled(): bool
    {
        return $this->kind === 'schedule';
    }
}
