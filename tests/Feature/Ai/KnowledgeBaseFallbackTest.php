<?php

use App\Models\AiConversation;
use App\Models\AiKnowledgeArticle;
use App\Models\AiQueryLog;
use App\Models\User;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Orchestrator\AiOrchestrator;
use App\Services\AI\Tools\JobTools\GenerateJdTool;
use Database\Seeders\RolePermissionSeeder;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('recruiter');
    $this->conversation = AiConversation::factory()->create(['user_id' => $this->user->id, 'title' => AiOrchestrator::DEFAULT_TITLE]);
});

test('with no provider configured the copilot lists matching published knowledge articles with a not-configured note', function (): void {
    AiKnowledgeArticle::factory()->create(['title' => 'Notice Period Policy', 'content' => 'The standard notice period is 30 days.', 'is_published' => true]);
    AiKnowledgeArticle::factory()->create(['title' => 'Notice Period Draft', 'content' => 'Unpublished notice period draft.', 'is_published' => false]);

    $result = app(AiOrchestrator::class)->ask($this->conversation, 'What is the notice period?', $this->user);

    expect($result['message']->content)
        ->toContain('Notice Period Policy')
        ->toContain('30 days')
        ->toContain('Full AI is not configured')
        ->toContain('AI_PROVIDER')
        ->not->toContain('Notice Period Draft')
        ->and($result['pending'])->toBe([])
        ->and(AiQueryLog::query()->where('user_id', $this->user->id)->exists())->toBeTrue()
        ->and($this->conversation->fresh()->title)->toBe('What is the notice period?');
});

test('the fallback says clearly when no published knowledge article matches', function (): void {
    $result = app(AiOrchestrator::class)->ask($this->conversation, 'zzznomatchzzz question', $this->user);

    expect($result['message']->content)
        ->toContain("couldn't find any published knowledge base articles")
        ->toContain('Full AI is not configured');
});

test('the unconfigured provider message points at AI_PROVIDER and GEMINI_API_KEY, not only OPENAI_API_KEY', function (): void {
    $response = app(AiGateway::class)->generate([LlmMessage::user('hi')], [], 'balanced');

    expect($response->content)->toContain('AI_PROVIDER')->toContain('GEMINI_API_KEY')->toContain('OPENAI_API_KEY');
});

test('a generation tool returns a graceful failure instead of throwing when the provider errors', function (): void {
    $this->app->instance(LLMProviderInterface::class, new ScriptedLlmProvider([new RuntimeException('provider down')]));

    $result = app(GenerateJdTool::class)->handle(['title' => 'Laravel Developer'], $this->user);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('could not draft a job description');
});

test('a generation tool explains that AI is not configured when no provider is set', function (): void {
    $result = app(GenerateJdTool::class)->handle(['title' => 'Laravel Developer'], $this->user);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('not configured');
});
