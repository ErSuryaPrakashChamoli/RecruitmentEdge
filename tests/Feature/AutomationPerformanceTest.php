<?php

use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Jobs\RunAutomationExecutionJob;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\NextBestAction\NextBestActionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->recruiter = Employee::factory()->create();
    $this->user = User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
});

function queriesDuring(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('next best actions for a user do not issue a query per application', function (): void {
    $requisition = RecruitmentRequisition::factory()->create();
    $seed = fn (int $n) => CandidateApplication::factory()->count($n)->create(['requisition_id' => $requisition->id, 'recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::Selected, 'last_activity_at' => now()->subDays(9)])
        ->each(fn (CandidateApplication $a) => Interview::factory()->create(['candidate_application_id' => $a->id, 'status' => InterviewStatus::Scheduled, 'scheduled_at' => now()->addHours(5)]));
    $service = app(NextBestActionService::class);

    $seed(3);
    $service->forUser($this->user);
    $small = queriesDuring(fn () => $service->forUser($this->user));
    $seed(20);
    // Seeding creates employees, which flushes the per-request hierarchy memo (Phase 8.9): warm it
    // up again exactly as before the first measurement, so both compare steady-state query counts.
    $service->forUser($this->user);
    $large = queriesDuring(fn () => $service->forUser($this->user));

    expect($large)->toBe($small);
});

test('a time-based sweep over many records is bounded and queues the work', function (): void {
    Queue::fake();
    AutomationRule::factory()->active()->create(['trigger' => 'interview.upcoming', 'timing' => ['amount' => 24, 'unit' => 'hours']]);
    $applications = CandidateApplication::factory()->count(60)->create(['recruiter_id' => $this->recruiter->id]);
    $applications->each(fn (CandidateApplication $a) => Interview::factory()->create(['candidate_application_id' => $a->id, 'status' => InterviewStatus::Scheduled, 'scheduled_at' => now()->addHours(3)]));

    $this->artisan('recruitment:automation:dispatch', ['--limit' => 25])->assertSuccessful();

    expect(AutomationExecution::query()->count())->toBe(25)
        ->and(AutomationExecution::query()->where('status', 'pending')->count())->toBe(25);
    Queue::assertPushed(RunAutomationExecutionJob::class, 25);

    $this->artisan('recruitment:automation:dispatch', ['--limit' => 25]);

    expect(AutomationExecution::query()->distinct()->count('subject_id'))->toBe(50);
});

test('events with no active rules cost a single cached lookup', function (): void {
    $application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
    app(AutomationEngine::class)->activeTriggers();

    $queries = queriesDuring(fn () => app(AutomationEngine::class)->trigger('candidate.stage_changed', $application));

    expect($queries)->toBe(0);
});
