<?php

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\MemoryType;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\HiringMemoryRecord;
use App\Models\Offer;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\CandidateJoiningService;
use App\Services\Intelligence\HiringMemoryService;
use App\Services\Intelligence\RoleDnaService;
use App\Services\OfferService;
use App\Services\RequisitionApprovalService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->actingAs($this->user);
});

test('a hire is captured with its facts when the candidate joins, once', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::JoiningConfirmed, 'application_date' => now()->subDays(30)]);
    $application->candidate->update(['skills' => ['PHP', 'SQL'], 'source_id' => CandidateSource::factory()->create(['name' => 'LinkedIn'])->id, 'email' => 'secret@example.com']);

    app(StageTransitionService::class)->transitionTo($application->fresh(), CandidateStage::Joined);
    app(HiringMemoryService::class)->captureHire($application->fresh());

    $memory = HiringMemoryRecord::query()->sole();

    expect($memory->memory_type)->toBe(MemoryType::Hire)
        ->and($memory->facts['skills'])->toBe(['PHP', 'SQL'])
        ->and($memory->facts['source'])->toBe('LinkedIn')
        ->and($memory->facts['days_to_hire'])->toBe(30)
        ->and(json_encode($memory->facts))->not->toContain('secret@example.com')
        ->and($memory->evidence()->count())->toBeGreaterThan(0);
});

test('rejections, declined offers, failed joinings and requisition closures are captured from the real services', function (): void {
    $reason = RecruitmentRejectionReason::factory()->create(['name' => 'Salary mismatch']);

    $rejected = CandidateApplication::factory()->create();
    app(StageTransitionService::class)->reject($rejected, $reason);

    $offer = Offer::factory()->create(['status' => OfferStatus::Released]);
    app(OfferService::class)->moveTo($offer, OfferStatus::Rejected, null, 'Took another offer', $reason);

    $joiner = CandidateApplication::factory()->create(['current_stage' => CandidateStage::OfferAccepted]);
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $joiner->id, 'status' => JoiningStatus::Expected]);
    app(CandidateJoiningService::class)->markNoShow($joining, $reason);

    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
    app(RequisitionApprovalService::class)->moveTo($requisition, RequisitionStatus::Closed, $this->user->employee, 'Filled');

    $types = HiringMemoryRecord::query()->pluck('memory_type')->map->value->sort()->values()->all();

    expect($types)->toContain('rejection', 'offer_outcome', 'joining_outcome', 'requisition_outcome')
        ->and(HiringMemoryRecord::query()->where('memory_type', MemoryType::Rejection)->where('candidate_application_id', $rejected->id)->sole()->facts['reason'])->toBe('Salary mismatch')
        ->and(HiringMemoryRecord::query()->where('memory_type', MemoryType::RequisitionOutcome)->sole()->facts['outcome'])->toBe('closed');
});

test('capture is idempotent', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Joined]);
    $service = app(HiringMemoryService::class);

    $service->captureHire($application);
    $service->captureHire($application);

    expect(HiringMemoryRecord::query()->count())->toBe(1);
});

test('memory is immutable; a correction creates a new version and keeps the old one', function (): void {
    $record = app(HiringMemoryService::class)->captureHire(CandidateApplication::factory()->create(['current_stage' => CandidateStage::Joined]));

    expect(fn () => $record->update(['summary' => 'rewritten']))->toThrow(LogicException::class)
        ->and(fn () => app(HiringMemoryService::class)->correct($record, ['not_a_fact' => 1], 'x', $this->user))->toThrow(DomainException::class);

    $record = $record->fresh();
    $corrected = app(HiringMemoryService::class)->correct($record, ['source' => 'Referral'], 'Source was recorded wrongly', $this->user);

    expect($corrected->version)->toBe(2)
        ->and($corrected->supersedes_id)->toBe($record->id)
        ->and($corrected->facts['source'])->toBe('Referral')
        ->and($record->fresh()->is_current)->toBeFalse()
        ->and($record->fresh()->facts['source'])->not->toBe('Referral');
});

test('memory from past hires feeds the next Role DNA of the same designation', function (): void {
    $requisition = RecruitmentRequisition::factory()->create();

    foreach (range(1, 3) as $i) {
        $hire = CandidateApplication::factory()->create(['requisition_id' => RecruitmentRequisition::factory()->create(['designation_id' => $requisition->designation_id])->id, 'current_stage' => CandidateStage::Joined]);
        $hire->candidate->update(['skills' => ['Kafka', "Skill{$i}"]]);
        app(HiringMemoryService::class)->captureHire($hire->fresh());
    }

    $dna = collect(app(RoleDnaService::class)->currentVersionFor($requisition)->dna)->keyBy('key');

    expect($dna['history:hires']['value'])->toBe('3 recorded')
        ->and($dna['history:skill:kafka']['value'])->toBe('3 of 3 hires');
});
