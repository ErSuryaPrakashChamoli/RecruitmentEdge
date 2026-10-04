<?php

use App\Models\CandidateApplication;
use App\Models\CodeSequence;
use App\Models\Tenant;
use App\Services\Lifecycle\RowLock;
use App\Services\SequenceCodeGenerator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Tests\Concurrency\Race;

/*
 * SaaS-1: tenant integrity under real two-transaction concurrency on MySQL 8.4 / REPEATABLE READ.
 * Run with: vendor/bin/pest -c phpunit.concurrency.xml
 */
beforeEach(function (): void {
    Race::prepareDatabase();
    Storage::fake('local');

    $this->home = TenantContext::current()->tenant();
    $this->other = Tenant::query()->firstOrCreate(['slug' => 'concurrency-other'], [
        'name' => 'Concurrency Other', 'status' => 'active', 'timezone' => 'Asia/Kolkata', 'locale' => 'en', 'currency' => 'INR', 'country' => 'IN',
    ]);

    // Each tenant's sequence exists already (steady state): a race is about its row lock.
    foreach ([$this->home, $this->other] as $tenant) {
        TenantContext::current()->run($tenant, fn () => app(SequenceCodeGenerator::class)->next('TNR'));
    }
});

afterEach(function (): void {
    foreach ([$this->home, $this->other] as $tenant) {
        TenantContext::current()->run($tenant, fn () => CodeSequence::query()->where('key', 'like', 'tnr:%')->delete());
    }
});

test('two tenants numbering records at the same moment never wait for each other, and each keeps its own sequence', function (): void {
    $other = $this->other;

    $race = Race::run(
        holder: fn () => app(SequenceCodeGenerator::class)->next('TNR'),
        contender: fn () => TenantContext::current()->run($other, fn () => app(SequenceCodeGenerator::class)->next('TNR')),
    );

    $last = fn (Tenant $tenant) => TenantContext::current()->run($tenant, fn () => CodeSequence::query()->where('key', 'tnr:'.now()->year)->value('last_number'));

    expect($race['blocked'])->toBeFalse()
        ->and($race['completed'])->toBeTrue()
        ->and($last($this->home))->toBe(2)
        ->and($last($this->other))->toBe(2);
});

test('within one tenant the same race still waits, and hands out distinct numbers', function (): void {
    $race = Race::run(
        holder: fn () => app(SequenceCodeGenerator::class)->next('TNR'),
        contender: fn () => app(SequenceCodeGenerator::class)->next('TNR'),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and(CodeSequence::query()->where('key', 'tnr:'.now()->year)->value('last_number'))->toBe(3);
});

test('a row lock held in one tenant is never taken, waited on or read by another tenant asking for the same id', function (): void {
    $application = CandidateApplication::factory()->create();
    $other = $this->other;

    $race = Race::run(
        holder: fn () => RowLock::key(CandidateApplication::class, $application->id),
        contender: fn () => TenantContext::current()->run($other, function () use ($application): void {
            RowLock::key(CandidateApplication::class, $application->id);

            if (CandidateApplication::query()->find($application->id) !== null) {
                throw new RuntimeException('Another tenant read the row.');
            }
        }),
    );

    expect($race['completed'])->toBeTrue()
        ->and($race['exception'])->toBeNull()
        ->and($race['blocked'])->toBeFalse();
});
