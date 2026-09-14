<?php

use App\Models\AiConversation;
use App\Models\AiUsageLog;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\AI\Actions\ActionExecutor;
use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\WebSearchProviderInterface;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\LlmResponse;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Tools\CandidateTools\SummarizeCandidateTool;
use App\Services\AI\Tools\PlannerTools\BuildRecruitmentPlanTool;
use App\Services\AI\Tools\ToolRegistry;
use Database\Seeders\RolePermissionSeeder;
use Tests\Feature\Ai\Fakes\RecordingEmbeddingProvider;
use Tests\Feature\Ai\Fakes\RecordingWebSearchProvider;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

test('chat usage is logged with a cost computed from the configured per-model pricing', function (): void {
    config(['ai.models.balanced' => 'priced-model', 'ai.pricing' => ['priced-model' => ['input' => 1.00, 'output' => 4.00]]]);
    $this->app->instance(LLMProviderInterface::class, new ScriptedLlmProvider([ScriptedLlmProvider::text('hi', 500_000, 250_000)]));

    app(AiGateway::class)->generate([LlmMessage::user('hi')], [], 'balanced');

    $log = AiUsageLog::query()->latest('id')->first();

    expect((float) $log->cost)->toBe(1.5)
        ->and($log->input_tokens)->toBe(500_000)
        ->and($log->model)->toBe('priced-model');
});

test('cached input tokens are billed at the cached rate when one is configured', function (): void {
    config(['ai.models.balanced' => 'cached-model', 'ai.pricing' => ['cached-model' => ['input' => 1.00, 'cached_input' => 0.25, 'output' => 0.0]]]);
    $this->app->instance(LLMProviderInterface::class, new ScriptedLlmProvider([
        new LlmResponse('hi', [], ['input_tokens' => 1_000_000, 'output_tokens' => 0, 'cached_tokens' => 400_000], 'cached-model'),
    ]));

    app(AiGateway::class)->generate([LlmMessage::user('hi')], [], 'balanced');

    expect((float) AiUsageLog::query()->latest('id')->value('cost'))->toBe(0.7);
});

test('a model with no pricing entry is logged with a null cost rather than zero', function (): void {
    config(['ai.models.balanced' => 'mystery-model']);
    $this->app->instance(LLMProviderInterface::class, new ScriptedLlmProvider([ScriptedLlmProvider::text('hi', 1000, 1000)]));

    app(AiGateway::class)->generate([LlmMessage::user('hi')], [], 'balanced');

    expect(AiUsageLog::query()->latest('id')->value('cost'))->toBeNull();
});

test('structured() calls are logged with the tokens the provider reports', function (): void {
    config(['ai.models.extraction' => 'priced-model', 'ai.pricing' => ['priced-model' => ['input' => 1.00, 'output' => 1.00]]]);
    $this->app->instance(LLMProviderInterface::class, new ScriptedLlmProvider(
        structuredResult: ['ok' => true],
        structuredUsage: ['input_tokens' => 1000, 'output_tokens' => 200, 'cached_tokens' => 0],
    ));

    $result = app(AiGateway::class)->structured([LlmMessage::user('extract')], ['type' => 'object']);
    $log = AiUsageLog::query()->latest('id')->first();

    expect($result)->toBe(['ok' => true])
        ->and($log->input_tokens)->toBe(1000)
        ->and($log->output_tokens)->toBe(200)
        ->and((float) $log->cost)->toBe(0.0012);
});

test('embeddings route through the embeddings model config and log the embedding provider and reported tokens', function (): void {
    config(['ai.embeddings.model' => 'embed-model', 'ai.embeddings.provider' => 'openai', 'ai.provider' => 'gemini']);
    $embeddings = new RecordingEmbeddingProvider;
    $this->app->instance(EmbeddingProviderInterface::class, $embeddings);

    app(AiGateway::class)->embed(['some text']);
    $log = AiUsageLog::query()->latest('id')->first();

    expect($embeddings->models)->toBe(['embed-model'])
        ->and($log->request_type->value)->toBe('embedding')
        ->and($log->provider)->toBe('openai')
        ->and($log->input_tokens)->toBe(42);
});

test('web search uses its own model config instead of the chat model id', function (): void {
    config([
        'ai.features.web_search_enabled' => true,
        'ai.models.balanced' => 'chat-model',
        'ai.web_search.model' => 'search-model',
        'ai.web_search.provider' => 'openai',
    ]);
    $search = new RecordingWebSearchProvider;
    $this->app->instance(WebSearchProviderInterface::class, $search);

    app(AiGateway::class)->research('laravel salaries');
    $log = AiUsageLog::query()->latest('id')->first();

    expect($search->options[0]['model'])->toBe('search-model')
        ->and($log->model)->toBe('search-model')
        ->and($log->provider)->toBe('openai');
});

test('model calls made inside a tool are attributed to the conversation the tool runs in', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('chro');

    $this->app->instance(LLMProviderInterface::class, new ScriptedLlmProvider([ScriptedLlmProvider::text('## Role Summary')]));

    $conversation = AiConversation::factory()->create(['user_id' => $user->id]);
    $message = $conversation->messages()->create(['role' => 'assistant', 'content' => null]);
    $toolCall = $message->toolCalls()->create([
        'tool_name' => 'generate_jd',
        'provider_call_id' => 'call_jd',
        'arguments' => ['title' => 'Laravel Developer'],
        'risk_level' => 'recommend',
        'status' => 'pending',
        'requires_confirmation' => false,
    ]);

    $result = app(ActionExecutor::class)->runImmediate($toolCall, app(ToolRegistry::class)->find('generate_jd'), $user);

    expect($result->success)->toBeTrue()
        ->and(AiUsageLog::query()->latest('id')->value('conversation_id'))->toBe($conversation->id);
});

test('summarisation and planning tools route to their own model categories', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('chro');

    config(['ai.models.summarization' => 'summary-model', 'ai.models.planning' => 'planning-model', 'ai.models.balanced' => 'chat-model']);
    $provider = new ScriptedLlmProvider;
    $this->app->instance(LLMProviderInterface::class, $provider);

    $application = CandidateApplication::factory()->create();

    app(SummarizeCandidateTool::class)->handle(['candidate_id' => $application->candidate_id], $user);
    app(BuildRecruitmentPlanTool::class)->handle(['target_hires' => 5, 'days' => 30], $user);

    expect($provider->modelsCalled())->toBe(['summary-model', 'planning-model']);
});
