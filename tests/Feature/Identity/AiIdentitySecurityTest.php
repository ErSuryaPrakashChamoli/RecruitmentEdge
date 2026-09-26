<?php

use App\Enums\AiToolCallStatus;
use App\Enums\ApplicationStatus;
use App\Filament\Pages\AiCopilot;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiToolCall;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRejectionReason;
use App\Models\Role;
use App\Models\User;
use App\Services\AI\Actions\ActionExecutor;
use App\Services\Identity\HierarchyIntegrityService;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Identity\StaffAccessService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config(['ai.features.actions_enabled' => true]);
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
    $this->vpEmployee = Employee::factory()->reportingTo($this->chroEmployee)->create();
    $this->managerEmployee = Employee::factory()->reportingTo($this->vpEmployee)->create();
    $this->manager = User::factory()->create(['employee_id' => $this->managerEmployee->id])->assignRole('manager');
    $this->recruiter = Employee::factory()->reportingTo($this->managerEmployee)->create();
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'status' => ApplicationStatus::Active]);
    $this->otherManager = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->vpEmployee)->create()->id])->assignRole('manager');
});

/**
 * A reject_candidates action proposed by $requester in their own conversation, exactly as the
 * orchestrator records it.
 */
function aiProposal(User $requester, CandidateApplication $application): AiToolCall
{
    $message = AiMessage::query()->create(['conversation_id' => AiConversation::factory()->create(['user_id' => $requester->id])->id, 'role' => 'assistant', 'content' => null]);

    return AiToolCall::factory()->create([
        'message_id' => $message->id,
        'tool_name' => 'reject_candidates',
        'provider_call_id' => 'call_'.uniqid(),
        'arguments' => ['application_ids' => [$application->id], 'rejection_reason_id' => RecruitmentRejectionReason::factory()->create()->id],
        'risk_level' => 'high_impact',
        'status' => 'pending',
        'requires_confirmation' => true,
    ]);
}

test('a proposal records its requester, approval window and authority', function (): void {
    $call = aiProposal($this->manager, $this->application);

    expect($call->requested_by)->toBe($this->manager->id)
        ->and($call->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue()
        ->and($call->authority_fingerprint)->toHaveLength(64);
});

test('the requester can approve their own action within the window', function (): void {
    $call = aiProposal($this->manager, $this->application);

    app(ActionExecutor::class)->approve($call, $this->manager);

    expect($call->fresh()->status)->toBe(AiToolCallStatus::Executed)
        ->and($this->application->fresh()->status)->toBe(ApplicationStatus::Rejected);
});

test('nobody else can approve or reject someone\'s action, even with the permission and the scope', function (): void {
    $call = aiProposal($this->manager, $this->application);

    expect(fn () => app(ActionExecutor::class)->approve($call, $this->chro))->toThrow(DomainException::class, 'Only the person who asked')
        ->and(fn () => app(ActionExecutor::class)->reject($call, $this->chro))->toThrow(DomainException::class, 'Only the person who asked')
        ->and($call->fresh()->status)->toBe(AiToolCallStatus::Pending)
        ->and($this->application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('the Copilot conversation id cannot be tampered with from the browser', function (): void {
    actingAs($this->otherManager);

    expect(fn () => Livewire::test(AiCopilot::class)->set('conversationId', aiProposal($this->manager, $this->application)->message->conversation_id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('a tampered tool-call id never reaches another user\'s pending action through the Copilot', function (): void {
    $call = aiProposal($this->manager, $this->application);
    actingAs($this->otherManager);

    Livewire::test(AiCopilot::class)->call('approveToolCall', $call->id);

    expect($call->fresh()->status)->toBe(AiToolCallStatus::Pending)
        ->and($this->application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('an expired action cannot run', function (): void {
    $call = aiProposal($this->manager, $this->application);
    $this->travel(31)->minutes();

    expect(fn () => app(ActionExecutor::class)->approve($call, $this->manager))->toThrow(DomainException::class, 'expired')
        ->and($call->fresh()->status)->toBe(AiToolCallStatus::Expired)
        ->and($this->application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('the expiry sweep retires stale actions and tells the conversation', function (): void {
    $call = aiProposal($this->manager, $this->application);
    $fresh = aiProposal($this->otherManager, $this->application);
    $fresh->forceFill(['expires_at' => now()->addHour()])->save();
    $this->travel(31)->minutes();

    $this->artisan('ai:expire-pending-actions')->expectsOutputToContain('Expired 1')->assertSuccessful();

    expect($call->fresh()->status)->toBe(AiToolCallStatus::Expired)
        ->and($call->fresh()->invalidation_reason)->toBe('expired')
        ->and($fresh->fresh()->status)->toBe(AiToolCallStatus::Pending)
        ->and($call->message->conversation->messages()->where('role', 'tool')->where('tool_call_id', $call->provider_call_id)->exists())->toBeTrue();
});

test('a revoked or suspended requester\'s pending actions are invalidated and can never run', function (): void {
    $call = aiProposal($this->manager, $this->application);

    app(StaffAccessService::class)->suspend($this->manager, $this->chro, 'Investigation');

    expect($call->fresh()->status)->toBe(AiToolCallStatus::Invalidated)
        ->and($call->fresh()->invalidation_reason)->toBe('requester_suspended');

    app(StaffAccessService::class)->restore($this->manager, $this->chro, 'Cleared');

    expect(fn () => app(ActionExecutor::class)->approve($call->fresh(), $this->manager->fresh()))->toThrow(DomainException::class, 'already been decided')
        ->and($this->application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('a demoted requester\'s pending actions are invalidated', function (): void {
    $call = aiProposal($this->manager, $this->application);

    app(RoleAssignmentService::class)->syncUserRoles($this->manager, [Role::byKeyOrFail('recruiter')->id], $this->chro);

    expect($call->fresh()->status)->toBe(AiToolCallStatus::Invalidated)
        ->and($call->fresh()->invalidation_reason)->toBe('requester_roles_changed');
});

test('an action proposed before the requester\'s hierarchy scope changed is invalidated at approval', function (): void {
    $call = aiProposal($this->manager, $this->application);
    app(HierarchyIntegrityService::class)->reassign($this->recruiter, $this->vpEmployee->id, $this->chro);

    expect(fn () => app(ActionExecutor::class)->approve($call, $this->manager))->toThrow(DomainException::class, 'access has changed')
        ->and($call->fresh()->status)->toBe(AiToolCallStatus::Invalidated)
        ->and($this->application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('an approved action still runs exactly once', function (): void {
    $call = aiProposal($this->manager, $this->application);
    $stale = AiToolCall::query()->find($call->id);

    app(ActionExecutor::class)->approve($call, $this->manager);

    expect(fn () => app(ActionExecutor::class)->approve($stale, $this->manager))->toThrow(DomainException::class, 'already been decided')
        ->and($call->fresh()->status)->toBe(AiToolCallStatus::Executed);
});

test('a suspended user cannot open the Copilot at all', function (): void {
    app(StaffAccessService::class)->suspend($this->manager, $this->chro, 'Investigation');

    expect(AiCopilot::canAccess())->toBeFalse();

    actingAs($this->manager->fresh());
    expect(AiCopilot::canAccess())->toBeFalse();
});
