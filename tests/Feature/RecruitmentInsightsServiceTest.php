<?php

use App\Models\User;
use App\Services\RecruitmentInsightsService;

test('generate falls back to facts-only when no AI provider is configured', function (): void {
    $user = User::factory()->create();

    $result = app(RecruitmentInsightsService::class)->generate(
        viewer: null,
        user: $user,
        start: now()->startOfMonth(),
        end: now()->endOfMonth(),
    );

    expect($result['configured'])->toBeFalse()
        ->and($result['narrative'])->toBeNull()
        ->and($result['facts'])->toHaveKeys(['period', 'funnel', 'turn_up', 'positions_at_risk', 'pending_work', 'alerts']);
});

test('generate omits recruiter_accountability facts when no viewer employee is given', function (): void {
    $user = User::factory()->create();

    $result = app(RecruitmentInsightsService::class)->generate(null, $user, now()->startOfMonth(), now()->endOfMonth());

    expect($result['facts'])->not->toHaveKey('recruiter_accountability');
});
