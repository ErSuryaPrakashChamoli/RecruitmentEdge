<?php

use App\Enums\FollowupStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\RequisitionStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\PerformanceEngine;
use Carbon\Carbon;
use Mockery\MockInterface;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-14 10:00:00'));

    $this->titleCount = fn (User $user, string $title): int => $user->fresh()->notifications()->where('data->title', $title)->count();
});

test('a joining at risk is notified and not duplicated on a second run', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Expected,
        'expected_doj' => now()->subDay(),
    ]);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect($user->notifications()->where('data->title', '[Joining] Expected joiner did not join')->count())->toBe(1);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect($user->fresh()->notifications()->where('data->title', '[Joining] Expected joiner did not join')->count())->toBe(1);
});

test('an overdue vacancy notifies the requisition manager', function (): void {
    $manager = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $manager->id]);

    RecruitmentRequisition::factory()->create([
        'status' => RequisitionStatus::Open,
        'manager_id' => $manager->id,
        'opening_date' => now()->subDays(45),
    ]);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect($user->notifications()->where('data->title', '[Recruitment] Vacancy ageing exceeded')->count())->toBe(1);
});

test('follow-ups due today and overdue remind their owner once per day', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);

    RecruitmentFollowup::factory()->create(['recruiter_id' => $recruiter->id, 'status' => FollowupStatus::Pending, 'followup_date' => now()->setTime(16, 0)]);
    RecruitmentFollowup::factory()->create(['recruiter_id' => $recruiter->id, 'status' => FollowupStatus::Pending, 'followup_date' => now()->subDays(2)]);
    RecruitmentFollowup::factory()->create(['recruiter_id' => $recruiter->id, 'status' => FollowupStatus::Completed, 'followup_date' => now()->subDays(2)]);
    RecruitmentFollowup::factory()->create(['recruiter_id' => $recruiter->id, 'status' => FollowupStatus::Pending, 'followup_date' => now()->addDays(3)]);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();
    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect(($this->titleCount)($user, '[Follow-ups] Follow-up due today'))->toBe(1)
        ->and(($this->titleCount)($user, '[Follow-ups] Follow-up overdue'))->toBe(1);
});

test('an interview tomorrow reminds both the interviewer and the recruiter, including rescheduled interviews', function (): void {
    $recruiter = Employee::factory()->create();
    $recruiterUser = User::factory()->create(['employee_id' => $recruiter->id]);
    $interviewer = Employee::factory()->create();
    $interviewerUser = User::factory()->create(['employee_id' => $interviewer->id]);

    Interview::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'interviewer_id' => $interviewer->id,
        'status' => InterviewStatus::Rescheduled,
        'scheduled_at' => now()->addDay()->setTime(11, 0),
    ]);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect(($this->titleCount)($recruiterUser, '[Interviews] Interview tomorrow'))->toBe(1)
        ->and(($this->titleCount)($interviewerUser, '[Interviews] Interview tomorrow'))->toBe(1);
});

test('an unconfirmed interview within 48 hours reminds the recruiter, a confirmed one does not', function (): void {
    $recruiter = Employee::factory()->create();
    $recruiterUser = User::factory()->create(['employee_id' => $recruiter->id]);
    $confirmedRecruiter = Employee::factory()->create();
    $confirmedRecruiterUser = User::factory()->create(['employee_id' => $confirmedRecruiter->id]);

    Interview::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => InterviewStatus::Scheduled,
        'scheduled_at' => now()->addHours(40),
    ]);
    Interview::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $confirmedRecruiter->id])->id,
        'status' => InterviewStatus::Confirmed,
        'scheduled_at' => now()->addHours(40),
    ]);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect(($this->titleCount)($recruiterUser, '[Interviews] Interview not yet confirmed'))->toBe(1)
        ->and(($this->titleCount)($confirmedRecruiterUser, '[Interviews] Interview not yet confirmed'))->toBe(0);
});

test('a joining reminder goes to the recruiter joining_reminder_days before the DOJ', function (): void {
    RecruitmentSetting::put('joining_reminder_days', 4, 'int');

    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);

    CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => JoiningStatus::Confirmed,
        'expected_doj' => now()->addDays(4),
    ]);
    CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => JoiningStatus::Confirmed,
        'expected_doj' => now()->addDays(6),
    ]);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect(($this->titleCount)($user, '[Joining] Joining reminder'))->toBe(1);
});

test("a confirmed joiner past their DOJ escalates to the recruiter's manager", function (): void {
    $manager = Employee::factory()->create();
    $managerUser = User::factory()->create(['employee_id' => $manager->id]);
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    $recruiterUser = User::factory()->create(['employee_id' => $recruiter->id]);

    CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => JoiningStatus::Confirmed,
        'expected_doj' => now()->subDays(2),
    ]);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();
    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect(($this->titleCount)($managerUser, '[Joining] Confirmed joiner did not join'))->toBe(1)
        ->and(($this->titleCount)($recruiterUser, '[Joining] Expected joiner did not join'))->toBe(1);
});

test('an expected (unconfirmed) joiner past their DOJ is not escalated', function (): void {
    $manager = Employee::factory()->create();
    $managerUser = User::factory()->create(['employee_id' => $manager->id]);
    $recruiter = Employee::factory()->reportingTo($manager)->create();

    CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => JoiningStatus::Expected,
        'expected_doj' => now()->subDays(2),
    ]);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect(($this->titleCount)($managerUser, '[Joining] Confirmed joiner did not join'))->toBe(0);
});

test('month-to-date composite scores drive recruiter, escalation, and team underperformance alerts', function (): void {
    $manager = Employee::factory()->create();
    $managerUser = User::factory()->create(['employee_id' => $manager->id]);
    $critical = Employee::factory()->reportingTo($manager)->create();
    $criticalUser = User::factory()->create(['employee_id' => $critical->id]);
    $below = Employee::factory()->reportingTo($manager)->create();
    $belowUser = User::factory()->create(['employee_id' => $below->id]);
    $unscored = Employee::factory()->reportingTo($manager)->create();

    foreach ([$critical, $below, $unscored] as $recruiter) {
        CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    }

    $this->partialMock(PerformanceEngine::class, function (MockInterface $mock) use ($critical, $below): void {
        $mock->shouldReceive('compositeScoreFor')->andReturnUsing(function (Employee $recruiter, $start, $end) use ($critical, $below): ?float {
            expect($start->toDateString())->toBe('2026-09-01');

            return match ($recruiter->id) {
                $critical->id => 40.0,
                $below->id => 60.0,
                default => null,
            };
        });
    });

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();
    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect(($this->titleCount)($criticalUser, '[Performance] Recruiter significantly below target'))->toBe(1)
        ->and(($this->titleCount)($belowUser, '[Performance] Recruiter below target'))->toBe(1)
        ->and(($this->titleCount)($managerUser, '[Performance] Team member significantly below target'))->toBe(1)
        ->and(($this->titleCount)($managerUser, '[Performance] Team below target'))->toBe(1);
});

test('no team alert is raised when the direct reports average meets the threshold', function (): void {
    $manager = Employee::factory()->create();
    $managerUser = User::factory()->create(['employee_id' => $manager->id]);
    $strong = Employee::factory()->reportingTo($manager)->create();
    $weak = Employee::factory()->reportingTo($manager)->create();

    foreach ([$strong, $weak] as $recruiter) {
        CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    }

    $this->partialMock(PerformanceEngine::class, function (MockInterface $mock) use ($strong): void {
        $mock->shouldReceive('compositeScoreFor')->andReturnUsing(fn (Employee $recruiter): float => $recruiter->id === $strong->id ? 120.0 : 60.0);
    });

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect(($this->titleCount)($managerUser, '[Performance] Team below target'))->toBe(0);
});
