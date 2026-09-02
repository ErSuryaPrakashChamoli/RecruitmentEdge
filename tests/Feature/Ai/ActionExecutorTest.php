<?php

use App\Enums\ApplicationStatus;
use App\Models\AiActionLog;
use App\Models\AiConversation;
use App\Models\AiToolCall;
use App\Models\CandidateApplication;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\AI\Actions\ActionExecutor;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->executor = app(ActionExecutor::class);
});

function makePendingToolCall(string $toolName, array $arguments, string $riskLevel = 'high_impact'): AiToolCall
{
    $conversation = AiConversation::factory()->create();
    $message = $conversation->messages()->create(['role' => 'assistant', 'content' => null]);

    return $message->toolCalls()->create([
        'tool_name' => $toolName,
        'provider_call_id' => 'call_'.uniqid(),
        'arguments' => $arguments,
        'risk_level' => $riskLevel,
        'status' => 'pending',
        'requires_confirmation' => true,
    ]);
}

test('a user without ai.actions.execute cannot approve a pending high-impact tool call', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);
    $reason = RecruitmentRejectionReason::factory()->create();

    $toolCall = makePendingToolCall('reject_candidates', [
        'application_ids' => [$application->id],
        'rejection_reason_id' => $reason->id,
    ]);

    $user = User::factory()->create();
    $user->assignRole('recruiter'); // recruiter role has no ai.actions.execute by default

    expect(fn () => $this->executor->approve($toolCall, $user))->toThrow(DomainException::class);

    expect($application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('approving a pending reject_candidates tool call actually rejects the application via StageTransitionService', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);
    $reason = RecruitmentRejectionReason::factory()->create();

    $toolCall = makePendingToolCall('reject_candidates', [
        'application_ids' => [$application->id],
        'rejection_reason_id' => $reason->id,
    ]);

    $approver = User::factory()->create();
    $approver->assignRole('chro');

    $result = $this->executor->approve($toolCall, $approver);

    expect($result->success)->toBeTrue()
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($application->fresh()->rejection_reason_id)->toBe($reason->id)
        ->and($toolCall->fresh()->status->value)->toBe('executed')
        ->and($toolCall->fresh()->approved_by)->toBe($approver->id);

    expect(AiActionLog::query()->where('tool_name', 'reject_candidates')->where('status', 'executed')->exists())->toBeTrue();
});

test('approving an already-decided tool call is rejected', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);
    $reason = RecruitmentRejectionReason::factory()->create();

    $toolCall = makePendingToolCall('reject_candidates', [
        'application_ids' => [$application->id],
        'rejection_reason_id' => $reason->id,
    ]);

    $approver = User::factory()->create();
    $approver->assignRole('chro');

    $this->executor->approve($toolCall, $approver);

    expect(fn () => $this->executor->approve($toolCall, $approver))->toThrow(DomainException::class);
});

test('rejecting a pending tool call marks it rejected and never touches the underlying record', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);
    $reason = RecruitmentRejectionReason::factory()->create();

    $toolCall = makePendingToolCall('reject_candidates', [
        'application_ids' => [$application->id],
        'rejection_reason_id' => $reason->id,
    ]);

    $approver = User::factory()->create();
    $approver->assignRole('chro');

    $this->executor->reject($toolCall, $approver, 'Not needed after all');

    expect($toolCall->fresh()->status->value)->toBe('rejected')
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and(AiActionLog::query()->where('tool_name', 'reject_candidates')->where('status', 'rejected')->exists())->toBeTrue();
});
