<?php

use App\Enums\AiMessageRole;
use App\Models\AiActionLog;
use App\Models\AiConversation;
use App\Models\AiToolResult;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\Orchestrator\AiOrchestrator;
use Database\Seeders\RolePermissionSeeder;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->application = CandidateApplication::factory()->create();
    $this->application->candidate->update([
        'full_name' => 'PRIVATE-CANDIDATE-ALICE', 'email' => 'PRIVATE-ALICE@example.invalid', 'mobile' => '9999912345',
        'expected_salary' => 99999999, 'remarks' => 'PRIVATE-REMARK-TEXT',
    ]);
    $this->conversation = AiConversation::factory()->create(['user_id' => $this->user->id]);
});

/**
 * Every provider-bound message across all recorded calls, as one string.
 */
function toolBoundaryProviderPayload(ScriptedLlmProvider $provider): string
{
    return (string) json_encode(collect($provider->calls)->flatMap(fn (array $call) => array_map(
        fn (LlmMessage $m) => [$m->role, $m->content, $m->toolCalls],
        $call['messages'],
    ))->all());
}

test('tool output is sanitized before it is stored, logged or replayed to the provider', function (): void {
    $provider = new ScriptedLlmProvider([
        ScriptedLlmProvider::toolCall('get_candidate', ['candidate_id' => $this->application->candidate_id]),
        ScriptedLlmProvider::text('Candidate reviewed.'),
    ]);
    app()->instance(LLMProviderInterface::class, $provider);

    app(AiOrchestrator::class)->ask($this->conversation, 'Show me this candidate', $this->user);

    $stored = json_encode([
        AiToolResult::query()->pluck('output'),
        AiActionLog::query()->pluck('output'),
        $this->conversation->messages()->where('role', AiMessageRole::Tool)->pluck('content'),
    ]);

    foreach (['PRIVATE-CANDIDATE-ALICE', 'PRIVATE-ALICE@example.invalid', '9999912345', '99999999', 'PRIVATE-REMARK-TEXT'] as $sentinel) {
        expect($stored)->not->toContain($sentinel)
            ->and(toolBoundaryProviderPayload($provider))->not->toContain($sentinel);
    }

    expect(toolBoundaryProviderPayload($provider))->toContain($this->application->candidate->candidate_code);
});

test('legacy history stored before the boundary is cleaned again every time it is replayed', function (): void {
    $this->conversation->messages()->create(['role' => AiMessageRole::User, 'content' => 'Email PRIVATE-ALICE@example.invalid please']);
    $this->conversation->messages()->create(['role' => AiMessageRole::Tool, 'tool_call_id' => 'legacy', 'tool_name' => 'get_candidate',
        'content' => json_encode(['success' => true, 'data' => ['candidate' => ['full_name' => 'PRIVATE-CANDIDATE-ALICE', 'mobile' => '9999912345', 'expected_salary' => 99999999, 'candidate_code' => 'CAND-2026-000001']]])]);
    $provider = new ScriptedLlmProvider([ScriptedLlmProvider::text('ok')]);
    app()->instance(LLMProviderInterface::class, $provider);

    app(AiOrchestrator::class)->ask($this->conversation, 'Continue', $this->user);

    expect(toolBoundaryProviderPayload($provider))->not->toContain('PRIVATE-ALICE@example.invalid')
        ->not->toContain('PRIVATE-CANDIDATE-ALICE')->not->toContain('9999912345')->not->toContain('99999999')
        ->toContain('CAND-2026-000001')
        ->and($this->conversation->messages()->where('tool_call_id', 'legacy')->value('content'))->toContain('PRIVATE-CANDIDATE-ALICE');
});
