<?php

use App\Enums\ActivityType;
use App\Enums\IncentiveCalculationStatus;
use App\Filament\Resources\RecruitmentDailyActivities\Pages\CreateRecruitmentDailyActivity;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\RecruitmentActivityService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.5 (SEC-4, D37/D42): activities feed targets, scores and incentive pay, so who may log
 * them, for whom, for which day and how they are corrected is decided in one service.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $this->teamRecruiter = Employee::factory()->reportingTo($this->manager)->create();
    $this->outsider = Employee::factory()->create();
    $this->recruiterUser = User::factory()->create(['employee_id' => $this->teamRecruiter->id])->assignRole('recruiter');
    $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
    $this->service = app(RecruitmentActivityService::class);
});

function activityAuthorityData(Employee $recruiter, array $overrides = []): array
{
    return ['recruiter_id' => $recruiter->id, 'activity_type' => ActivityType::Call->value, 'activity_datetime' => now()->subHour(), ...$overrides];
}

test('a recruiter logs their own activity, recorded as created by them and audited', function (): void {
    actingAs($this->recruiterUser);

    $activity = $this->service->log($this->recruiterUser, activityAuthorityData($this->teamRecruiter));

    expect($activity->created_by)->toBe($this->teamRecruiter->id)
        ->and(AuditLog::query()->where('action', 'activity_logged')->where('auditable_id', $activity->id)->exists())->toBeTrue();
});

test('a manager may log for someone in their team', function (): void {
    expect($this->service->log($this->managerUser, activityAuthorityData($this->teamRecruiter))->recruiter_id)->toBe($this->teamRecruiter->id);
});

test('nobody can log activity against a person outside their hierarchy', function (): void {
    $this->service->log($this->recruiterUser, activityAuthorityData($this->outsider));
})->throws(DomainException::class, 'only for yourself or someone in your team');

test('a peer cannot log activity for another recruiter', function (): void {
    $peer = Employee::factory()->reportingTo($this->manager)->create();

    $this->service->log($this->recruiterUser, activityAuthorityData($peer));
})->throws(DomainException::class);

test('activity cannot be dated in the future', function (): void {
    $this->service->log($this->recruiterUser, activityAuthorityData($this->teamRecruiter, ['activity_datetime' => now()->addDay()]));
})->throws(DomainException::class, 'has not happened yet');

test('activity cannot be backdated beyond the configured window', function (): void {
    RecruitmentSetting::put('activity_backdate_days', '3', 'int');

    $this->service->log($this->recruiterUser, activityAuthorityData($this->teamRecruiter, ['activity_datetime' => now()->subDays(5)]));
})->throws(DomainException::class, 'at most 3 day(s) back');

test('created_by can never be supplied by the caller', function (): void {
    $activity = $this->service->log($this->recruiterUser, activityAuthorityData($this->teamRecruiter, ['created_by' => $this->outsider->id]));

    expect($activity->created_by)->toBe($this->teamRecruiter->id);
});

test('an activity in a period whose incentive is approved can no longer be corrected or deleted', function (): void {
    $activity = $this->service->log($this->recruiterUser, activityAuthorityData($this->teamRecruiter));
    RecruiterIncentiveCalculation::factory()->create(['employee_id' => $this->teamRecruiter->id, 'status' => IncentiveCalculationStatus::Approved, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth()]);

    expect(fn () => $this->service->update($this->recruiterUser, $activity, ['remarks' => 'changed']))->toThrow(DomainException::class, 'already approved')
        ->and(fn () => $this->service->delete($this->recruiterUser, $activity))->toThrow(DomainException::class, 'already approved');
});

test('a correction outside an approved period is audited with before and after values', function (): void {
    $activity = $this->service->log($this->recruiterUser, activityAuthorityData($this->teamRecruiter));

    $this->service->update($this->managerUser, $activity, ['remarks' => 'Connected on second try']);

    expect(AuditLog::query()->where('action', 'activity_corrected')->where('auditable_id', $activity->id)->exists())->toBeTrue();
});

test('the Filament form cannot log activity for someone outside the hierarchy', function (): void {
    actingAs($this->recruiterUser);

    Livewire::test(CreateRecruitmentDailyActivity::class)
        ->fillForm(activityAuthorityData($this->outsider, ['activity_datetime' => now()->subHour()->format('Y-m-d H:i:s')]))
        ->call('create');

    expect(RecruitmentDailyActivity::query()->where('recruiter_id', $this->outsider->id)->exists())->toBeFalse();
});
