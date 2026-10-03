<?php

use App\Enums\CandidateStage;
use App\Enums\IncentiveTriggerEvent;
use App\Models\CandidateApplication;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Services\RecruiterIncentiveCalculator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concurrency\Race;

/**
 * Phase 8.10 (P810-DI-01, DI-02, DI-04): real two-transaction races on MySQL 8.4 / REPEATABLE READ,
 * as in IntegrityRaceTest. Each must fail on the pre-fix code.
 *
 * Run with: vendor/bin/pest -c phpunit.concurrency.xml
 */
beforeEach(function (): void {
    Race::prepareDatabase();
    Storage::fake('local');

    if (! DB::table('roles')->exists()) {
        $this->seed(RolePermissionSeeder::class);
    }
});

test('two calculations of one selection priced in different months record one calculation (DI-01)', function (): void {
    $rule = RecruitmentIncentiveRule::factory()->fixed(1000)->create(['trigger_event' => IncentiveTriggerEvent::Selection, 'effective_from' => now()->subYear()]);
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);

    $race = Race::run(
        holder: fn () => app(RecruiterIncentiveCalculator::class)->calculateForSelection(CandidateApplication::query()->find($application->id), now()),
        contender: fn () => app(RecruiterIncentiveCalculator::class)->calculateForSelection(CandidateApplication::query()->find($application->id), now()->addMonth()),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and(RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $rule->id)->count())->toBe(1);
});
