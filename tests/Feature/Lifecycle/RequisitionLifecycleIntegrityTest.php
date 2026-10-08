<?php

use App\Enums\RequisitionStatus;
use App\Filament\Resources\RecruitmentRequisitions\Pages\ListRecruitmentRequisitions;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\RequisitionApprovalService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->service = app(RequisitionApprovalService::class);
    $this->requester = Employee::factory()->create();
    User::factory()->create(['employee_id' => $this->requester->id])->assignRole('chro');
    $this->requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::PendingApproval, 'created_by' => $this->requester->id]);
});

test('the requester cannot approve their own requisition, and the attempt is audited', function (): void {
    expect(fn () => $this->service->approve($this->requisition, $this->requester))->toThrow(DomainException::class, 'someone else must approve')
        ->and($this->requisition->fresh()->status)->toBe(RequisitionStatus::PendingApproval)
        ->and(AuditLog::query()->where('action', 'requisition_self_approval_blocked')->where('auditable_id', $this->requisition->id)->exists())->toBeTrue();
});

test('another authorised approver can approve', function (): void {
    $approver = Employee::factory()->create();
    User::factory()->create(['employee_id' => $approver->id])->assignRole('chro');

    expect($this->service->approve($this->requisition, $approver)->status)->toBe(RequisitionStatus::Approved);
});

test('the approve action is not offered to the requester', function (): void {
    actingAs($this->requester->user);

    Livewire::test(ListRecruitmentRequisitions::class)->assertTableActionHidden('approve', $this->requisition);
});

test('a requisition status cannot be written outside RequisitionApprovalService', function (): void {
    expect(fn () => $this->requisition->update(['status' => RequisitionStatus::Approved]))->toThrow(LogicException::class, 'RequisitionApprovalService')
        ->and($this->requisition->fresh()->status)->toBe(RequisitionStatus::PendingApproval);
});
