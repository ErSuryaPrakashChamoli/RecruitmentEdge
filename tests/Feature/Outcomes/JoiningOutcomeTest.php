<?php

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\MemoryType;
use App\Enums\OutcomeType;
use App\Events\CandidateJoined;
use App\Events\EmployeeConvertedFromCandidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\HiringMemoryRecord;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Services\CandidateJoiningService;
use App\Services\EmployeeConversionService;
use App\Services\Intelligence\RoleDnaService;
use App\Services\StageTransitionService;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->requisition = RecruitmentRequisition::factory()->create(['skills' => ['Laravel', 'PHP'], 'salary_max' => 88888888]);
    $this->application = CandidateApplication::factory()->create(['requisition_id' => $this->requisition->id, 'current_stage' => CandidateStage::JoiningConfirmed, 'remarks' => 'PRIVATE-APP-REMARK']);
    $this->application->candidate->update([
        'full_name' => 'PRIVATE-CANDIDATE-ALICE', 'email' => 'PRIVATE-ALICE@example.invalid', 'mobile' => '9999912345',
        'expected_salary' => 99999999, 'remarks' => 'PRIVATE-CANDIDATE-REMARK', 'skills' => ['Laravel', 'Go'], 'total_experience' => 4,
    ]);
    $this->joining = CandidateJoining::factory()->create(['candidate_application_id' => $this->application->id, 'status' => JoiningStatus::Confirmed]);
});

/**
 * @return array<string, mixed>
 */
function joiningOutcomeEventProperties(object $event): array
{
    return get_object_vars($event);
}

test('marking a joining as joined raises CandidateJoined carrying ids only', function (): void {
    Event::fake([CandidateJoined::class]);

    app(CandidateJoiningService::class)->markJoined($this->joining, now()->subDay());

    Event::assertDispatched(CandidateJoined::class, function (CandidateJoined $event): bool {
        $properties = joiningOutcomeEventProperties($event);

        return $event->joiningId === $this->joining->id
            && $event->applicationId === $this->application->id
            && collect($properties)->every(fn ($value) => $value === null || is_scalar($value))
            && ! str_contains((string) json_encode($properties), 'PRIVATE');
    });
});

test('a joining record creates the completed-hire snapshot, outcomes and Hiring Memory', function (): void {
    app(CandidateJoiningService::class)->markJoined($this->joining, now()->subDay());

    $snapshot = HiringOutcomeSnapshot::query()->sole();

    expect($snapshot->candidate_joining_id)->toBe($this->joining->id)
        ->and($snapshot->facts['experience_band'])->toBe('3-5_years')
        ->and($snapshot->facts['required_skills_matched'])->toBe(1)
        ->and(HiringOutcome::query()->where('outcome_type', OutcomeType::Joined)->where('candidate_joining_id', $this->joining->id)->exists())->toBeTrue()
        ->and(HiringOutcome::query()->where('outcome_type', OutcomeType::TimeToHire)->where('hiring_outcome_snapshot_id', $snapshot->id)->exists())->toBeTrue()
        ->and(HiringMemoryRecord::query()->where('memory_type', MemoryType::Hire)->where('candidate_application_id', $this->application->id)->exists())->toBeTrue();
});

test('the Joined pipeline stage alone creates no completed hire', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::JoiningConfirmed]);

    app(StageTransitionService::class)->transitionTo($application, CandidateStage::Joined);

    expect($application->fresh()->current_stage)->toBe(CandidateStage::Joined)
        ->and(HiringOutcomeSnapshot::query()->count())->toBe(0)
        ->and(HiringOutcome::query()->count())->toBe(0)
        ->and(HiringMemoryRecord::query()->where('memory_type', MemoryType::Hire)->count())->toBe(0);
});

test('the snapshot holds no names, contact details, pay or remarks', function (): void {
    app(CandidateJoiningService::class)->markJoined($this->joining, now()->subDay());
    $stored = json_encode(HiringOutcomeSnapshot::query()->sole()->toArray()).json_encode(HiringOutcome::query()->get()->toArray());

    foreach (['PRIVATE-CANDIDATE-ALICE', 'PRIVATE-ALICE@example.invalid', '9999912345', '99999999', '88888888', 'PRIVATE-CANDIDATE-REMARK', 'PRIVATE-APP-REMARK'] as $sentinel) {
        expect(str_contains($stored, $sentinel))->toBeFalse("snapshot stored {$sentinel}");
    }
});

test('the snapshot does not change when the candidate, requisition or Role DNA change later', function (): void {
    app(RoleDnaService::class)->currentVersionFor($this->requisition);
    app(CandidateJoiningService::class)->markJoined($this->joining, now()->subDay());
    $before = HiringOutcomeSnapshot::query()->sole()->getAttributes();

    $this->application->candidate->update(['skills' => ['Rust'], 'total_experience' => 12]);
    $this->requisition->update(['skills' => ['Rust']]);
    app(RoleDnaService::class)->rebuild($this->requisition->fresh());

    expect(HiringOutcomeSnapshot::query()->sole()->getAttributes())->toBe($before);
});

test('converting to an employee raises an ids-only event', function (): void {
    app(CandidateJoiningService::class)->markJoined($this->joining, now()->subDay());
    Event::fake([EmployeeConvertedFromCandidate::class]);

    $employee = app(EmployeeConversionService::class)->convert($this->joining->fresh());

    Event::assertDispatched(EmployeeConvertedFromCandidate::class, fn (EmployeeConvertedFromCandidate $event) => $event->employeeId === $employee->id
        && $event->applicationId === $this->application->id
        && collect(joiningOutcomeEventProperties($event))->every(fn ($value) => $value === null || is_scalar($value)));
});

test('conversion links the employee to the hiring snapshot', function (): void {
    app(CandidateJoiningService::class)->markJoined($this->joining, now()->subDay());

    $employee = app(EmployeeConversionService::class)->convert($this->joining->fresh());

    expect(HiringOutcomeSnapshot::query()->sole()->employee_id)->toBe($employee->id);
});

test('replaying the joining event records nothing twice', function (): void {
    app(CandidateJoiningService::class)->markJoined($this->joining, now()->subDay());
    $counts = [HiringOutcomeSnapshot::query()->count(), HiringOutcome::query()->count(), HiringMemoryRecord::query()->count()];

    Event::dispatch(new CandidateJoined($this->application->candidate_id, $this->application->id, $this->requisition->id, $this->joining->id, now()->toDateString()));

    expect([HiringOutcomeSnapshot::query()->count(), HiringOutcome::query()->count(), HiringMemoryRecord::query()->count()])->toBe($counts);
});

test('a no-show is recorded from the joining record with its structured reason', function (): void {
    $reason = RecruitmentRejectionReason::factory()->create(['name' => 'Accepted another offer']);

    app(CandidateJoiningService::class)->markNoShow($this->joining, $reason);

    $outcome = HiringOutcome::query()->where('candidate_joining_id', $this->joining->id)->sole();

    expect($outcome->outcome_type)->toBe(OutcomeType::NoShow)
        ->and($outcome->details['reason'])->toBe('Accepted another offer')
        ->and(HiringOutcomeSnapshot::query()->count())->toBe(0);
});
