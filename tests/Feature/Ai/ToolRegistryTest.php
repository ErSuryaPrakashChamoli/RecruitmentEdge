<?php

use App\Enums\ApplicationStatus;
use App\Models\AiConversation;
use App\Models\AiToolCall;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Orchestrator\AiOrchestrator;
use App\Services\AI\Tools\ActionTools\MoveCandidatesStageTool;
use App\Services\AI\Tools\CandidateTools\SearchCandidatesTool;
use App\Services\AI\Tools\ToolRegistry;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->registry = app(ToolRegistry::class);
});

test('a user without candidates.viewAny is never offered search_candidates', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('ai.query');

    expect($this->registry->userMayUse($user, (new SearchCandidatesTool)->name()))->toBeFalse()
        ->and($this->registry->forUser($user))->not->toContainEqual($this->registry->find('search_candidates'));
});

test('a recruiter is offered search_candidates because the role grants candidates.viewAny', function (): void {
    $user = User::factory()->create();
    $user->assignRole('recruiter');

    expect($this->registry->userMayUse($user, 'search_candidates'))->toBeTrue();
});

test('write/external/high-impact tools disappear entirely when AI actions are disabled', function (): void {
    config(['ai.features.actions_enabled' => false]);

    $user = User::factory()->create();
    $user->assignRole('chro');

    $names = collect($this->registry->forUser($user))->map(fn ($tool) => $tool->name());

    expect($names)->not->toContain((new MoveCandidatesStageTool(app(StageTransitionService::class)))->name())
        ->and($names)->toContain('search_candidates');
});

test('write/external/high-impact tools reappear once AI actions are enabled again', function (): void {
    config(['ai.features.actions_enabled' => true]);

    $user = User::factory()->create();
    $user->assignRole('chro');

    $names = collect($this->registry->forUser($user))->map(fn ($tool) => $tool->name());

    expect($names)->toContain('move_candidates_stage');
});

test('users without ai.actions.execute are never offered write, external, or high-impact tools', function (): void {
    config(['ai.features.actions_enabled' => true]);

    $user = User::factory()->create();
    $user->givePermissionTo(['ai.query', 'candidates.viewAny', 'candidates.reassign', 'pipeline.transition']);

    $names = collect($this->registry->definitionsForUser($user))->pluck('name');

    expect($names)->toContain('search_candidates')
        ->and($names)->not->toContain('reject_candidates')
        ->and($names)->not->toContain('assign_candidates_to_recruiter')
        ->and($this->registry->isOfferedTo($user, 'reject_candidates'))->toBeFalse();
});

test('the orchestrator refuses a write tool call the model was never offered', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);

    $this->app->instance(LLMProviderInterface::class, new ScriptedLlmProvider([
        ScriptedLlmProvider::toolCall('reject_candidates', ['application_ids' => [$application->id], 'rejection_reason_id' => 1]),
        ScriptedLlmProvider::text('I cannot do that.'),
    ]));

    $user = User::factory()->create();
    $user->givePermissionTo(['ai.query', 'candidates.viewAny', 'pipeline.transition']);
    $conversation = AiConversation::factory()->create(['user_id' => $user->id]);

    $result = app(AiOrchestrator::class)->ask($conversation, 'Reject that candidate', $user);

    expect($result['pending'])->toBe([])
        ->and(AiToolCall::query()->where('tool_name', 'reject_candidates')->value('status')->value)->toBe('failed')
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Active);
});
