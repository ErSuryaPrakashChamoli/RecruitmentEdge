<?php

use App\Enums\ApplicationStatus;
use App\Enums\JoiningStatus;
use App\Filament\Resources\Interviewers\Pages\ManageInterviewers;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Interviewer;
use App\Models\User;
use App\Services\InterviewService;
use App\Services\MasterDataLifecycleService;
use App\Services\Outcomes\HiringSnapshotService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.6: interviewers are deactivated, never deleted, and a new interview needs an active
 * listed interviewer (D8.6-009); a hiring snapshot freezes its dimension names (D8.6-008).
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create()->assignRole('chro');
    actingAs($this->admin);
});

test('an interviewer cannot be deleted, only deactivated with a reason, and it is audited', function (): void {
    $interviewer = Interviewer::factory()->create();

    expect(fn () => $interviewer->delete())->toThrow(DomainException::class)
        ->and($this->admin->can('delete', $interviewer))->toBeFalse()
        ->and($this->admin->can('deleteAny', Interviewer::class))->toBeFalse();

    Livewire::test(ManageInterviewers::class)
        ->assertTableActionDoesNotExist('delete')
        ->callTableAction('deactivateInterviewer', $interviewer, data: ['reason' => 'Left the panel'])
        ->assertHasNoTableActionErrors();

    $row = AuditLog::query()->where('auditable_type', Interviewer::class)->where('auditable_id', $interviewer->id)->where('action', 'updated')->sole();

    expect($interviewer->fresh()->is_active)->toBeFalse()
        ->and($row->reason)->toBe('Left the panel')
        ->and($row->changes)->toBe(['is_active' => false]);
});

test('a new interview needs an interviewer on the active list', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);
    $unlisted = Employee::factory()->create();
    $deactivated = Interviewer::factory()->inactive()->create();
    $listed = Interviewer::factory()->create();
    $service = app(InterviewService::class);
    $data = fn (int $interviewerId): array => ['interviewer_id' => $interviewerId, 'scheduled_at' => now()->addDay(), 'mode' => 'phone'];

    expect(fn () => $service->schedule($application, $data($unlisted->id)))->toThrow(DomainException::class, 'active interviewer list')
        ->and(fn () => $service->schedule($application, $data($deactivated->employee_id)))->toThrow(DomainException::class, 'active interviewer list');

    expect($service->schedule($application, $data($listed->employee_id))->interviewer_id)->toBe($listed->employee_id);
});

test('FAILURE 2: a hiring snapshot keeps the names it was captured with after master data is renamed or archived', function (): void {
    $joining = lifecycleFixture(fn () => CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => now()->subDay()]));
    $requisition = $joining->candidateApplication->requisition;
    $source = $joining->candidateApplication->candidate->source;

    $snapshot = app(HiringSnapshotService::class)->captureForJoining($joining);
    $originalDepartment = $requisition->department->name;
    $originalSource = $source->name;

    $requisition->department->update(['name' => 'Renamed Department']);
    $source->update(['name' => 'Renamed Source']);

    $fresh = HiringOutcomeSnapshot::query()->find($snapshot->id);

    expect($fresh->rules_version)->toBe('hiring-snapshot/3')
        ->and($fresh->dimensionName('department'))->toBe($originalDepartment)
        ->and($fresh->dimensionName('source'))->toBe($originalSource)
        ->and($fresh->dimensionName('designation'))->toBe($requisition->designation->name);
});

test('an older snapshot without frozen names falls back to the current, even archived, record', function (): void {
    $joining = lifecycleFixture(fn () => CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => now()->subDay()]));
    $snapshot = app(HiringSnapshotService::class)->captureForJoining($joining);
    HiringOutcomeSnapshot::query()->whereKey($snapshot->id)->update(['department_name' => null, 'rules_version' => 'hiring-snapshot/2']);
    $department = $joining->candidateApplication->requisition->department;
    $department->update(['name' => 'Current Name']);
    app(MasterDataLifecycleService::class)->deactivate($this->admin, $department->fresh(), 'Merged');
    $department->fresh()->delete();

    expect(HiringOutcomeSnapshot::query()->find($snapshot->id)->dimensionName('department'))->toBe('Current Name');
});
