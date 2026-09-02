<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\DocumentStatus;
use App\Enums\IncentiveCalculationStatus;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\Priority;
use App\Enums\RecruitmentCostStatus;
use App\Enums\RequisitionStatus;

/**
 * Every status/priority enum used as a table badge must define color() for every case (Section
 * 32: one centralized badge system, not a per-page match arm). A valid color is one of Filament's
 * named palette keys configured on the panel (see AdminPanelProvider::colors()).
 */
$validColors = ['success', 'warning', 'danger', 'info', 'gray', 'primary'];

$enums = [
    ApplicationStatus::class,
    OfferStatus::class,
    JoiningStatus::class,
    RequisitionStatus::class,
    Priority::class,
    IncentiveCalculationStatus::class,
    InterviewStatus::class,
    InterviewResult::class,
    DocumentStatus::class,
    CandidateStage::class,
    RecruitmentCostStatus::class,
];

foreach ($enums as $enumClass) {
    test("every {$enumClass} case has a valid color", function () use ($enumClass, $validColors): void {
        foreach ($enumClass::cases() as $case) {
            expect($case->color())->toBeString()
                ->and($validColors)->toContain($case->color());
        }
    });
}

test('ApplicationStatus reserves danger exclusively for rejected/dropout', function (): void {
    expect(ApplicationStatus::Rejected->color())->toBe('danger')
        ->and(ApplicationStatus::Dropout->color())->toBe('danger')
        ->and(ApplicationStatus::Active->color())->toBe('success');
});

test('CandidateStage never uses danger, since rejection is a separate ApplicationStatus not a stage', function (): void {
    foreach (CandidateStage::cases() as $stage) {
        expect($stage->color())->not->toBe('danger');
    }
});
