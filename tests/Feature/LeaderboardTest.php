<?php

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Filament\Pages\Leaderboard;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterPerformanceRule;
use App\Models\RecruiterPerformanceSnapshot;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentDailyTarget;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the leaderboard renders the real, hierarchy-scoped recruiter table, not an empty page', function (): void {
    $recruiter = Employee::factory()->create(['first_name' => 'Priya', 'last_name' => 'Recruiter']);
    CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    RecruiterPerformanceSnapshot::factory()->create([
        'employee_id' => $recruiter->id,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'score' => 82.5,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    actingAs($user)
        ->get('/admin/leaderboard')
        ->assertSuccessful()
        ->assertSee('Priya Recruiter')
        ->assertSee('82.5');
});

test('the leaderboard summary cards reflect real seeded scores, not a fabricated figure', function (): void {
    $top = Employee::factory()->create();
    CandidateApplication::factory()->create(['recruiter_id' => $top->id]);
    RecruiterPerformanceSnapshot::factory()->create([
        'employee_id' => $top->id,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'score' => 90,
    ]);

    $second = Employee::factory()->create();
    CandidateApplication::factory()->create(['recruiter_id' => $second->id]);
    RecruiterPerformanceSnapshot::factory()->create([
        'employee_id' => $second->id,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'score' => 70,
    ]);

    $user = User::factory()->create(['employee_id' => $top->id]);
    $user->assignRole('chro');
    actingAs($user);

    $summary = Livewire::test(Leaderboard::class)->instance()->getSummary();

    expect($summary['total'])->toBe(2)
        ->and($summary['scored'])->toBe(2)
        ->and($summary['average'])->toBe(80.0)
        ->and($summary['topName'])->toBe($top->fullName())
        ->and($summary['topScore'])->toBe(90.0);
});

test('the leaderboard computes the composite live and shows actual versus target when no snapshot exists yet', function (): void {
    $recruiter = Employee::factory()->create(['first_name' => 'Live', 'last_name' => 'Scorer']);
    CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Monthly,
        'target_value' => 10,
        'effective_from' => now()->startOfMonth(),
    ]);
    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Calls, 'weightage' => 100, 'effective_from' => now()->startOfMonth()]);
    RecruitmentDailyActivity::factory()->count(12)->create([
        'recruiter_id' => $recruiter->id,
        'activity_type' => ActivityType::Call,
        'outcome' => ActivityOutcome::Connected,
        'activity_datetime' => now(),
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    $page = Livewire::test(Leaderboard::class)
        ->assertSee('Connected')
        ->assertSee('Shortlisted')
        ->assertSee('Offers')
        ->assertTableColumnStateSet('calls', '12 / 10', $recruiter)
        ->assertTableColumnStateSet('connected_calls', '12', $recruiter)
        ->assertTableColumnStateSet('score', 120.0, $recruiter)
        ->assertTableColumnHasDescription('score', 'Live', $recruiter);

    expect($page->instance()->getSummary())
        ->scored->toBe(1)
        ->topScore->toBe(120.0);
});

test('the leaderboard uses the stored snapshot score instead of recomputing when one exists', function (): void {
    $recruiter = Employee::factory()->create();
    CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Calls, 'weightage' => 100, 'effective_from' => now()->startOfMonth()]);
    RecruiterPerformanceSnapshot::factory()->create([
        'employee_id' => $recruiter->id,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'score' => 64.5,
        'breakdown' => [['metric' => TargetMetric::Calls->value, 'weight' => 100, 'target' => 20, 'actual' => 13, 'achievement' => 64.5]],
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(Leaderboard::class)
        ->assertTableColumnStateSet('score', 64.5, $recruiter)
        ->assertTableColumnDoesNotHaveDescription('score', 'Live', $recruiter)
        ->assertTableColumnStateSet('controllable_achievement', 64.5, $recruiter);
});
