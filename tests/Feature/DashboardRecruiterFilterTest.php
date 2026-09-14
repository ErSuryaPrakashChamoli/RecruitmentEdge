<?php

use App\Filament\Widgets\FollowUpCalendar;
use App\Filament\Widgets\RecruitmentActionCenterWidget;
use App\Filament\Widgets\TodaysRecruitmentPulse;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentFollowup;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->manager = Employee::factory()->create();
    $this->recruiterA = Employee::factory()->reportingTo($this->manager)->create();
    $this->recruiterB = Employee::factory()->reportingTo($this->manager)->create();
    $this->outsider = Employee::factory()->create();

    $user = User::factory()->create(['employee_id' => $this->manager->id]);
    $user->assignRole('manager');
    actingAs($user);
});

test('selecting a visible recruiter scopes dashboard widgets to that recruiter', function (): void {
    RecruitmentFollowup::factory()->create(['recruiter_id' => $this->recruiterA->id, 'followup_date' => now()->subDay()]);
    RecruitmentFollowup::factory()->count(2)->create(['recruiter_id' => $this->recruiterB->id, 'followup_date' => now()->subDay()]);

    $overdueCount = fn (array $filters): int => Livewire::test(RecruitmentActionCenterWidget::class, ['pageFilters' => $filters])
        ->instance()
        ->getPendingWork()
        ->firstWhere('key', 'overdue_followups')['count'];

    expect($overdueCount([]))->toBe(3)
        ->and($overdueCount(['recruiter_id' => $this->recruiterA->id]))->toBe(1);
});

test('a recruiter outside the viewer hierarchy in the filter is ignored', function (): void {
    RecruitmentFollowup::factory()->create(['recruiter_id' => $this->recruiterA->id, 'followup_date' => now()->subDay()]);
    RecruitmentFollowup::factory()->count(4)->create(['recruiter_id' => $this->outsider->id, 'followup_date' => now()->subDay()]);

    $item = Livewire::test(RecruitmentActionCenterWidget::class, ['pageFilters' => ['recruiter_id' => $this->outsider->id]])
        ->instance()
        ->getPendingWork()
        ->firstWhere('key', 'overdue_followups');

    expect($item['count'])->toBe(1);
});

test('the follow-up calendar honours the recruiter filter', function (): void {
    $this->freezeTime();

    $applicationA = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiterA->id]);
    $applicationA->candidate->update(['full_name' => 'Recruiter A Candidate']);
    Interview::factory()->create(['candidate_application_id' => $applicationA->id, 'scheduled_at' => now()->addDay()->setTime(9, 0)]);

    $applicationB = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiterB->id]);
    $applicationB->candidate->update(['full_name' => 'Recruiter B Candidate']);
    Interview::factory()->create(['candidate_application_id' => $applicationB->id, 'scheduled_at' => now()->addDay()->setTime(9, 0)]);

    Livewire::test(FollowUpCalendar::class, ['pageFilters' => ['recruiter_id' => $this->recruiterA->id]])
        ->call('selectDate', now()->addDay()->toDateString())
        ->assertSee('Recruiter A Candidate')
        ->assertDontSee('Recruiter B Candidate');
});

test("today's pulse follows the period filter, defaults to today, and covers screening and selections", function (): void {
    $this->travelTo(Carbon::parse('2026-09-14 10:00:00'));

    CandidateApplication::factory()->create(['recruiter_id' => $this->recruiterA->id]);
    Candidate::factory()->create(['created_by' => $this->recruiterA->id]);

    $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
    Candidate::factory()->create(['created_by' => $this->recruiterA->id]);
    $this->travelTo(Carbon::parse('2026-09-14 10:00:00'));

    $rows = fn (array $filters) => Livewire::test(TodaysRecruitmentPulse::class, ['pageFilters' => $filters])
        ->instance()
        ->getRows()
        ->keyBy('label');

    expect($rows([])->get('Profiles Sourced')['actual'])->toBe(1)
        ->and($rows(['period' => 'this_month'])->get('Profiles Sourced')['actual'])->toBe(2)
        ->and($rows(['period' => 'this_month'])->get('Profiles Sourced')['today'])->toBe(1)
        ->and($rows([])->keys()->all())->toContain('Screening', 'Selections');
});
