<?php

use App\Models\AiConversation;
use App\Models\User;
use App\Services\AI\Exceptions\AiRateLimitExceededException;
use App\Services\AI\Orchestrator\AiOrchestrator;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('asking with no AI provider configured still persists the turn and returns a graceful message', function (): void {
    $user = User::factory()->create();
    $user->assignRole('recruiter');
    $conversation = AiConversation::factory()->create(['user_id' => $user->id]);

    $result = app(AiOrchestrator::class)->ask($conversation, 'What needs my attention today?', $user);

    expect($conversation->messages()->count())->toBe(2)
        ->and($result['message']->content)->toContain('not configured')
        ->and($result['pending'])->toBe([]);
});

test('sending messages faster than the configured rate limit is rejected', function (): void {
    config(['ai.limits.rate_limit_per_minute' => 2]);

    $user = User::factory()->create();
    $user->assignRole('recruiter');
    $conversation = AiConversation::factory()->create(['user_id' => $user->id]);

    app(AiOrchestrator::class)->ask($conversation, 'first', $user);
    app(AiOrchestrator::class)->ask($conversation, 'second', $user);

    expect(fn () => app(AiOrchestrator::class)->ask($conversation, 'third', $user))
        ->toThrow(AiRateLimitExceededException::class);
});
