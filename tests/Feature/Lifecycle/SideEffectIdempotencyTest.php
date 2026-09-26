<?php

use App\Jobs\RunAutomationExecutionJob;
use App\Models\AiActionLog;
use App\Models\AiConversation;
use App\Models\AiToolCall;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\Actions\ActionExecutor;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config(['ai.features.actions_enabled' => true]);
    $this->approver = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->application = CandidateApplication::factory()->create();
    $this->newRecruiter = Employee::factory()->create();

    $message = AiConversation::factory()->create(['user_id' => $this->approver->id])->messages()->create(['role' => 'assistant', 'content' => null]);
    $this->toolCall = $message->toolCalls()->create([
        'tool_name' => 'assign_candidates_to_recruiter',
        'provider_call_id' => 'call_'.uniqid(),
        'arguments' => ['application_ids' => [$this->application->id], 'recruiter_employee_id' => $this->newRecruiter->id],
        'risk_level' => 'write',
        'status' => 'pending',
        'requires_confirmation' => true,
    ]);
});

test('an approved AI action runs once even when two approvals race on the same pending call', function (): void {
    $first = AiToolCall::query()->find($this->toolCall->id);
    $second = AiToolCall::query()->find($this->toolCall->id);
    $executor = app(ActionExecutor::class);

    $executor->approve($first, $this->approver);

    expect(fn () => $executor->approve($second, $this->approver))->toThrow(DomainException::class, 'already been decided')
        ->and(AiActionLog::query()->where('tool_name', 'assign_candidates_to_recruiter')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'application_reassigned')->count())->toBe(1)
        ->and($this->toolCall->fresh()->status->value)->toBe('executed');
});

test('a call already approved cannot then be rejected by a stale request', function (): void {
    $stale = AiToolCall::query()->find($this->toolCall->id);
    app(ActionExecutor::class)->approve($this->toolCall, $this->approver);

    expect(fn () => app(ActionExecutor::class)->reject($stale, $this->approver))->toThrow(DomainException::class, 'already been decided')
        ->and($this->toolCall->fresh()->status->value)->toBe('executed');
});

test('the automation job\'s uniqueness lock expires so a lost job cannot block retries', function (): void {
    expect((new RunAutomationExecutionJob(1))->uniqueFor)->toBe(3600);
});
