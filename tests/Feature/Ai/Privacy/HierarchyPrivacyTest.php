<?php

use App\Models\AiConversation;
use App\Models\AiToolCall;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Orchestrator\AiOrchestrator;
use Database\Seeders\RolePermissionSeeder;
use Tests\Feature\Ai\Fakes\PrivacyRecordingLlmProvider;

const HIERARCHY_PRIVACY_B_SENTINELS = ['PRIVATE-CANDIDATE-B', 'private-b@example.invalid', '9666612345', '66666666', 'PRIVATE-B-REMARK'];

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $recruiterA = Employee::factory()->create();
    $recruiterB = Employee::factory()->create();
    $this->userA = User::factory()->create(['employee_id' => $recruiterA->id])->assignRole('recruiter');
    $this->appA = CandidateApplication::factory()->create(['recruiter_id' => $recruiterA->id]);
    $this->appB = CandidateApplication::factory()->create(['recruiter_id' => $recruiterB->id, 'remarks' => 'PRIVATE-B-REMARK']);
    $this->appB->candidate->update(['full_name' => 'PRIVATE-CANDIDATE-B', 'email' => 'private-b@example.invalid', 'mobile' => '9666612345', 'expected_salary' => 66666666, 'remarks' => 'PRIVATE-B-REMARK']);
});

/**
 * @param  array<string, mixed>  $arguments
 */
function hierarchyPrivacyRun(User $user, string $tool, array $arguments): array
{
    $provider = new PrivacyRecordingLlmProvider($tool, $arguments);
    app()->instance(LLMProviderInterface::class, $provider);
    $conversation = AiConversation::factory()->create(['user_id' => $user->id]);

    app(AiOrchestrator::class)->ask($conversation, 'Tell me about this candidate', $user);

    return [$provider, AiToolCall::query()->where('tool_name', $tool)->latest('id')->first()];
}

test('User A can use the AI with their own candidate', function (): void {
    [$provider, $call] = hierarchyPrivacyRun($this->userA, 'get_candidate', ['candidate_id' => $this->appA->candidate_id]);

    expect($call->result->success)->toBeTrue()
        ->and($provider->payload())->toContain($this->appA->candidate->candidate_code);
});

test('User A cannot reach User B\'s candidate through any tool, and nothing about it reaches the provider', function (string $tool, Closure $arguments): void {
    [$provider, $call] = hierarchyPrivacyRun($this->userA, $tool, $arguments->call($this));

    expect($call->result->success)->toBeFalse()
        ->and($call->result->error)->toMatch('/not found|not visible|at least two visible/i')
        ->and($provider->payload())->not->toContain($this->appB->candidate->candidate_code)
        ->and($provider->payload())->not->toContain($this->appB->application_code);

    foreach (HIERARCHY_PRIVACY_B_SENTINELS as $sentinel) {
        expect(str_contains($provider->payload(), $sentinel))->toBeFalse("{$tool} leaked {$sentinel}");
    }
})->with([
    'get_candidate' => ['get_candidate', fn () => ['candidate_id' => $this->appB->candidate_id]],
    'summarize_candidate' => ['summarize_candidate', fn () => ['candidate_id' => $this->appB->candidate_id]],
    'get_candidate_timeline' => ['get_candidate_timeline', fn () => ['application_id' => $this->appB->id]],
    'recommend_next_step' => ['recommend_next_step', fn () => ['application_id' => $this->appB->id]],
    'find_duplicate_candidates' => ['find_duplicate_candidates', fn () => ['candidate_id' => $this->appB->candidate_id]],
    'compare_candidates' => ['compare_candidates', fn () => ['candidate_ids' => [$this->appA->candidate_id, $this->appB->candidate_id]]],
    'summarize_interview_feedback' => ['summarize_interview_feedback', fn () => ['application_id' => $this->appB->id]],
    'explain_talent_signal' => ['explain_talent_signal', fn () => ['application_id' => $this->appB->id]],
]);
