<?php

namespace App\Services\Governance;

use App\Services\Outcomes\OutcomeLearningService;
use App\Services\Outcomes\OutcomeService;

/**
 * Phase 8.6 (D8.6-028): the configuration-in-code whose values change historical results — the
 * Outcome Loop checkpoints and sample bands, and the metric timezone and sample floors — is
 * fingerprinted and pinned to the rule version that declares its meaning.
 *
 * - PINNED records, per group, the version and the fingerprint of the values that version was
 *   reviewed with. A test fails when a value changes without a new version (and a new pin), so a
 *   change to meaning can never slip in as a config edit.
 * - drift() compares the running configuration (which environment variables can override in a
 *   deployment) with the pin; `governance:audit` reports it.
 *
 * Only named, non-secret keys are read; values are hashed (SHA-256 of their canonical JSON) and
 * never logged. Operational settings (queues, cache TTLs, scan bounds) are deliberately excluded:
 * they do not change what a past result means.
 */
class ConfigurationFingerprint
{
    /**
     * The version of the shared metric settings (config/metrics.php semantic keys).
     */
    public const string METRICS_CONFIG_VERSION = 'metrics-config/1';

    /**
     * @var array<string, array{version: string, keys: array<int, string>}>
     */
    public const array GOVERNED = [
        'outcome_rules' => [
            'version' => OutcomeService::RULE_VERSION,
            'keys' => ['outcomes.status_observation_days', 'outcomes.status_observation_grace_days', 'outcomes.catch_up_days'],
        ],
        'outcome_learning' => [
            'version' => OutcomeLearningService::RULE_VERSION,
            'keys' => ['outcomes.sample.insufficient_below', 'outcomes.sample.stronger_from', 'outcomes.learning_checkpoint_days'],
        ],
        'metrics' => [
            'version' => self::METRICS_CONFIG_VERSION,
            'keys' => ['metrics.business_timezone', 'metrics.min_sample', 'metrics.compensation_min_group'],
        ],
    ];

    /**
     * The reviewed version and fingerprint of each group. Change a value → bump the version and
     * record the new fingerprint here (ConfigurationFingerprintTest prints it).
     *
     * @var array<string, array{version: string, fingerprint: string}>
     */
    public const array PINNED = [
        'outcome_rules' => ['version' => 'outcome-rules/1', 'fingerprint' => '73e5148426357dd4'],
        'outcome_learning' => ['version' => 'outcome-learning/1', 'fingerprint' => '248d4f4ac6056c01'],
        'metrics' => ['version' => 'metrics-config/1', 'fingerprint' => 'dbdcee8822fe020e'],
    ];

    /**
     * @return array<string, array{version: string, fingerprint: string}>
     */
    public function current(): array
    {
        return collect(self::GOVERNED)
            ->map(fn (array $group) => ['version' => $group['version'], 'fingerprint' => $this->fingerprint($group['keys'])])
            ->all();
    }

    /**
     * Groups whose running configuration differs from the pinned, reviewed values.
     *
     * @return array<string, string>
     */
    public function drift(): array
    {
        return collect($this->current())
            ->reject(fn (array $current, string $group) => $current === (self::PINNED[$group] ?? null))
            ->map(fn (array $current, string $group) => ($current['version'] !== (self::PINNED[$group]['version'] ?? null))
                ? "version {$current['version']} is not the pinned ".(self::PINNED[$group]['version'] ?? 'none')
                : "values differ from those reviewed for {$current['version']} (fingerprint {$current['fingerprint']})")
            ->all();
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function fingerprint(array $keys): string
    {
        $values = collect($keys)->mapWithKeys(fn (string $key) => [$key => config($key)])->all();

        return substr(hash('sha256', (string) json_encode($values, JSON_PRESERVE_ZERO_FRACTION)), 0, 16);
    }
}
