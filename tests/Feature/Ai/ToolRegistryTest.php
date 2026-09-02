<?php

use App\Models\User;
use App\Services\AI\Tools\ActionTools\MoveCandidatesStageTool;
use App\Services\AI\Tools\CandidateTools\SearchCandidatesTool;
use App\Services\AI\Tools\ToolRegistry;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;

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
