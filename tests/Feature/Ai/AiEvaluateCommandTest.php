<?php

use App\Enums\ApplicationStatus;
use App\Models\AiEvaluation;
use App\Models\AiEvaluationRun;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\AI\Contracts\LLMProviderInterface;
use Database\Seeders\AiEvaluationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

test('the default static run passes for every seeded evaluation without calling any provider', function (): void {
    Http::preventStrayRequests();
    $this->seed(AiEvaluationSeeder::class);

    $this->artisan('ai:evaluate')->assertSuccessful();

    expect(AiEvaluationRun::query()->count())->toBe(AiEvaluation::query()->count());
});

test('a static run fails when requires_confirmation disagrees with the tool\'s risk level', function (): void {
    AiEvaluation::factory()->create([
        'name' => 'Wrong confirmation expectation',
        'expected_tool' => 'search_candidates',
        'expected_permission' => 'candidates.viewAny',
        'assertions' => ['requires_confirmation' => true],
    ]);

    $this->artisan('ai:evaluate')
        ->expectsOutputToContain('requires_confirmation [true]')
        ->assertFailed();
});

test('--live refuses to run when no provider is configured', function (): void {
    AiEvaluation::factory()->create();

    $this->artisan('ai:evaluate', ['--live' => true])
        ->expectsOutputToContain('--live needs a configured AI provider')
        ->assertFailed();
});

test('--live checks the model called the expected tool and never executes a confirmation-gated action', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('chro');

    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);

    $this->app->instance(LLMProviderInterface::class, new ScriptedLlmProvider([
        ScriptedLlmProvider::toolCall('search_candidates', ['query' => 'Zebra']),
        ScriptedLlmProvider::text('Here they are.'),
        ScriptedLlmProvider::toolCall('reject_candidates', ['application_ids' => [$application->id], 'rejection_reason_id' => 1]),
    ]));

    $search = AiEvaluation::factory()->create(['name' => 'Live search', 'question' => 'Find Zebra', 'expected_tool' => 'search_candidates', 'expected_permission' => 'candidates.viewAny']);
    AiEvaluation::factory()->create([
        'name' => 'Live reject',
        'question' => 'Reject them',
        'expected_tool' => 'reject_candidates',
        'expected_permission' => 'pipeline.transition',
        'assertions' => ['requires_confirmation' => true],
    ]);

    $this->artisan('ai:evaluate', ['--live' => true, '--user' => $admin->email])->assertSuccessful();

    $run = $search->runs()->first();

    expect($run->passed)->toBeTrue()
        ->and($run->actual_output['mode'])->toBe('live')
        ->and($run->actual_output['called_tools'])->toContain('search_candidates')
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Active);
});
