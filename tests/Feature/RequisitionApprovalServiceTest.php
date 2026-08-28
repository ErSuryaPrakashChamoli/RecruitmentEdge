<?php

use App\Enums\RequisitionStatus;
use App\Models\RecruitmentRequisition;
use App\Services\RequisitionApprovalService;

beforeEach(function (): void {
    $this->service = app(RequisitionApprovalService::class);
});

test('a requisition can move through its full lifecycle', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Draft]);

    $this->service->submitForApproval($requisition);
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::PendingApproval);

    $this->service->approve($requisition);
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Approved);

    $this->service->open($requisition);
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Open);

    $this->service->hold($requisition);
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::OnHold);

    $this->service->close($requisition);
    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Closed);

    expect($requisition->statusHistory()->count())->toBe(5);
});

test('an invalid transition is rejected and the status is unchanged', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Draft]);

    try {
        $this->service->approve($requisition);
    } catch (DomainException) {
        // expected
    }

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Draft)
        ->and($requisition->statusHistory()->count())->toBe(0);
});

test('closed and cancelled are terminal', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed]);

    expect($this->service->allowedNextStatuses($requisition))->toBe([]);
});

test('allowedNextStatuses reflects the current status', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);

    expect(array_map(fn (RequisitionStatus $s) => $s->value, $this->service->allowedNextStatuses($requisition)))
        ->toBe(['on_hold', 'closed', 'cancelled']);
});
