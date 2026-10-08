<?php

use App\Services\Governance\ConfigurationFingerprint;

/**
 * Phase 8.6 (D8.6-028): configuration in code that changes historical results cannot change
 * without a new rule version — the fingerprint of the reviewed values is pinned per version.
 */
test('the governed configuration matches the values reviewed for its rule version', function (): void {
    $current = app(ConfigurationFingerprint::class)->current();

    expect($current)->toBe(ConfigurationFingerprint::PINNED, 'A governed config value changed: bump the rule version and pin the new fingerprint. Current: '.json_encode($current));
});

test('a changed value is reported as drift, naming the group but not the value', function (): void {
    config(['outcomes.status_observation_grace_days' => 14]);

    $drift = app(ConfigurationFingerprint::class)->drift();

    expect($drift)->toHaveKey('outcome_rules')
        ->not->toHaveKey('metrics')
        ->and(str_contains($drift['outcome_rules'], '14'))->toBeFalse();
});

test('the fingerprint reads only the named keys', function (): void {
    config(['outcomes.queue' => 'somewhere-else', 'metrics.cache_ttl' => 1]);

    expect(app(ConfigurationFingerprint::class)->drift())->toBe([]);
});
