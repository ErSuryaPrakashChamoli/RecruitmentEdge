<?php

namespace App\Services\Metrics;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The canonical list of governed metrics (Phase 8.5 D29: code-defined, reviewed in pull requests).
 * A metric that is not registered here is not governed; MetricGovernanceArchitectureTest keeps
 * dashboards, reports and Copilot tools on registered metrics for the headline numbers.
 */
class MetricRegistry
{
    /**
     * @var array<int, class-string<MetricDefinition>>
     */
    public const array DEFINITIONS = [
        Definitions\TimeToHire::class,
        Definitions\TimeToFill::class,
        Definitions\Hires::class,
        Definitions\TimeInStage::class,
        Definitions\FunnelConversion::class,
        Definitions\StageActivity::class,
        Definitions\OfferAcceptanceRate::class,
        Definitions\OfferDecidedAcceptanceRate::class,
        Definitions\JoinRate::class,
        Definitions\OfferToJoin::class,
        Definitions\JoiningNoShowRate::class,
        Definitions\JoiningDropoutRate::class,
        Definitions\InterviewTurnUpRate::class,
        Definitions\InterviewNoShowRate::class,
        Definitions\SourceToJoin::class,
        Definitions\CostPerHire::class,
        Definitions\SlaLegCompliance::class,
        Definitions\RequisitionAgeing::class,
        Definitions\RecruiterActivity::class,
        Definitions\RecruiterOutcomes::class,
        Definitions\TeamOutcomes::class,
        Definitions\HiringHealthStatus::class,
        Definitions\OutcomeJoinRate::class,
        Definitions\OutcomeOfferAcceptance::class,
        Definitions\OutcomeTimeToHire::class,
        Definitions\OutcomeTimeInStage::class,
        Definitions\OutcomeSourceToJoin::class,
        Definitions\OutcomeStatusObservations::class,
    ];

    /**
     * @var array<string, MetricDefinition>|null
     */
    private ?array $definitions = null;

    public function __construct(private readonly Container $container) {}

    public function get(string $key): MetricDefinition
    {
        return $this->definitions()[$key] ?? throw new InvalidArgumentException("Metric [{$key}] is not registered.");
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    /**
     * @return Collection<string, MetricDefinition>
     */
    public function all(): Collection
    {
        return collect($this->definitions());
    }

    /**
     * @return array<string, MetricDefinition>
     */
    private function definitions(): array
    {
        return $this->definitions ??= collect(self::DEFINITIONS)
            ->map(fn (string $class) => $this->container->make($class))
            ->keyBy(fn (MetricDefinition $definition) => $definition->key())
            ->all();
    }
}
