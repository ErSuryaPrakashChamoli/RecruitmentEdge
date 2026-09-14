<?php

use App\Enums\RequisitionStatus;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\RequisitionApprovalService;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->service = app(RequisitionApprovalService::class);

    $this->approver = Employee::factory()->create();
    User::factory()->create(['employee_id' => $this->approver->id])->assignRole('chro');
});

test('a requisition can move through its full lifecycle', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Draft]);

    $this->service->submitForApproval($requisition, $this->approver);
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::PendingApproval);

    $this->service->approve($requisition, $this->approver);
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Approved);

    $this->service->open($requisition, $this->approver);
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Open);

    $this->service->hold($requisition, $this->approver, 'Budget freeze');
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::OnHold);

    $this->service->resume($requisition, $this->approver);
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Open);

    $this->service->close($requisition, $this->approver, 'Position filled');
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Closed);

    expect($requisition->statusHistory()->count())->toBe(6);
});

test('an invalid transition is rejected and the status is unchanged', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Draft]);

    try {
        $this->service->approve($requisition, $this->approver);
    } catch (DomainException) {
        // expected
    }

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Draft)
        ->and($requisition->statusHistory()->count())->toBe(0);
});

test('approving requires the requisitions.approve permission of the acting employee', function (): void {
    $managerEmployee = Employee::factory()->create();
    User::factory()->create(['employee_id' => $managerEmployee->id])->assignRole('manager');

    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::PendingApproval]);

    expect(fn () => $this->service->approve($requisition, $managerEmployee))
        ->toThrow(DomainException::class, 'permission');

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::PendingApproval)
        ->and($requisition->statusHistory()->count())->toBe(0);
});

test('sending a pending requisition back to draft requires the approve permission', function (): void {
    $managerEmployee = Employee::factory()->create();
    User::factory()->create(['employee_id' => $managerEmployee->id])->assignRole('manager');

    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::PendingApproval]);

    expect(fn () => $this->service->sendBackToDraft($requisition, $managerEmployee, 'Fix the salary band'))
        ->toThrow(DomainException::class, 'permission');

    $this->service->sendBackToDraft($requisition, $this->approver, 'Fix the salary band');

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Draft);
});

test('approval with no actor falls back to the authenticated user', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::PendingApproval]);

    expect(fn () => $this->service->approve($requisition))->toThrow(DomainException::class);

    $chro = User::factory()->create();
    $chro->assignRole('chro');
    actingAs($chro);

    $this->service->approve($requisition);

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Approved);
});

test('hold, close, cancel and send back require a reason', function (RequisitionStatus $from, string $method): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => $from]);

    expect(fn () => $this->service->{$method}($requisition, $this->approver, '  '))
        ->toThrow(DomainException::class, 'reason is required');

    expect($requisition->refresh()->status)->toBe($from);
})->with([
    'hold' => [RequisitionStatus::Open, 'hold'],
    'close' => [RequisitionStatus::OnHold, 'close'],
    'cancel' => [RequisitionStatus::Draft, 'cancel'],
    'send back' => [RequisitionStatus::PendingApproval, 'sendBackToDraft'],
]);

test('closed and cancelled are terminal', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed]);

    expect($this->service->allowedNextStatuses($requisition))->toBe([]);
});

test('allowedNextStatuses reflects the current status', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);

    expect(array_map(fn (RequisitionStatus $s) => $s->value, $this->service->allowedNextStatuses($requisition)))
        ->toBe(['on_hold', 'closed', 'cancelled']);
});
