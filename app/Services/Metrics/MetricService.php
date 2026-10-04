<?php

namespace App\Services\Metrics;

use App\Services\Tenancy\TenantCache;
use Illuminate\Support\Facades\Cache;

/**
 * The single entry point every consumer uses to read a governed metric (Phase 8.5): dashboard
 * widgets, reports, exports, alerts and Copilot tools all call get(), so the same key, viewer,
 * period and filters give the same MetricResult everywhere.
 *
 * Caching (D14): a cacheable metric is kept for config metrics.cache_ttl seconds, keyed by metric
 * key, definition version, the viewer's visible-team fingerprint, the period and the filters — so a
 * definition change, a hierarchy change or a different viewer never reads another's result.
 * Invalidation is by expiry only; operational (real-time) metrics are never cached.
 */
class MetricService
{
    public function __construct(
        private readonly MetricRegistry $registry,
        private readonly MetricScope $scope,
    ) {}

    public function get(string $key, MetricQuery $query): MetricResult
    {
        $definition = $this->registry->get($key);
        $spec = $definition->spec();
        $ttl = (int) config('metrics.cache_ttl', 0);

        if ($ttl <= 0 || ! $spec->cacheable) {
            return $definition->compute($query);
        }

        return MetricResult::fromCache(Cache::remember($this->cacheKey($spec, $query), $ttl, fn () => $definition->compute($query)->toCache()));
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, MetricResult>
     */
    public function many(array $keys, MetricQuery $query): array
    {
        return collect($keys)->mapWithKeys(fn (string $key) => [$key => $this->get($key, $query)])->all();
    }

    public function spec(string $key): MetricSpec
    {
        return $this->registry->get($key)->spec();
    }

    private function cacheKey(MetricSpec $spec, MetricQuery $query): string
    {
        $filters = $query->filters;
        ksort($filters);

        // SaaS-1: under the tenant — two tenants' "view all" viewers never share an entry.
        return TenantCache::key(implode(':', [
            'metrics',
            $spec->key,
            'v'.$spec->version,
            $this->scope->fingerprint($query->viewer),
            $query->period?->key() ?? 'now',
            md5((string) json_encode($filters)),
        ]));
    }
}
