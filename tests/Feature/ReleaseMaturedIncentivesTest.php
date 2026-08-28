<?php

use App\Enums\IncentiveCalculationStatus;
use App\Models\RecruiterIncentiveCalculation;

test('moves matured retention-held calculations to Pending Verification', function (): void {
    $matured = RecruiterIncentiveCalculation::factory()->create([
        'status' => IncentiveCalculationStatus::Calculated,
        'retention_due_at' => now()->subDay(),
    ]);

    $notYetMatured = RecruiterIncentiveCalculation::factory()->create([
        'status' => IncentiveCalculationStatus::Calculated,
        'retention_due_at' => now()->addDays(10),
    ]);

    $noRetention = RecruiterIncentiveCalculation::factory()->create([
        'status' => IncentiveCalculationStatus::Calculated,
        'retention_due_at' => null,
    ]);

    $this->artisan('incentives:release-matured')
        ->expectsOutputToContain('Released 1 incentive calculation(s)')
        ->assertSuccessful();

    expect($matured->refresh()->status)->toBe(IncentiveCalculationStatus::PendingVerification)
        ->and($matured->approvals()->first()->remarks)->toBe('Retention period completed')
        ->and($notYetMatured->refresh()->status)->toBe(IncentiveCalculationStatus::Calculated)
        ->and($noRetention->refresh()->status)->toBe(IncentiveCalculationStatus::Calculated);
});
