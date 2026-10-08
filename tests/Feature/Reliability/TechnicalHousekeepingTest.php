<?php

use Illuminate\Support\Facades\DB;

/**
 * Phase 8.9 (P89-OPS-009, ED-08): expired database-cache entries are pruned — and only those. A
 * live entry (a lockout, a step-up code, a heartbeat) is never touched.
 */
test('only cache entries that have already expired are pruned, across batches', function (): void {
    config(['cache.default' => 'database']);
    $now = now()->getTimestamp();
    DB::table('cache')->insert(collect(range(1, 1203))->map(fn (int $i): array => ['key' => "expired-{$i}", 'value' => 's:1:"x";', 'expiration' => $now - $i])->all());
    DB::table('cache')->insert([
        ['key' => 'live-lockout', 'value' => 's:1:"x";', 'expiration' => $now + 60],
        ['key' => 'live-forever', 'value' => 's:1:"x";', 'expiration' => $now + 315360000],
    ]);

    $this->artisan('cache:prune-expired', ['--dry-run' => true])->expectsOutputToContain('1203 expired')->assertSuccessful();
    expect(DB::table('cache')->count())->toBe(1205);

    $this->artisan('cache:prune-expired')->expectsOutputToContain('Pruned 1203')->assertSuccessful();

    expect(DB::table('cache')->orderBy('key')->pluck('key')->all())->toBe(['live-forever', 'live-lockout']);
});

test('nothing is pruned when the cache does not live in the database', function (): void {
    config(['cache.default' => 'array']);
    DB::table('cache')->insert(['key' => 'expired', 'value' => 's:1:"x";', 'expiration' => now()->getTimestamp() - 10]);

    $this->artisan('cache:prune-expired')->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(1);
});
