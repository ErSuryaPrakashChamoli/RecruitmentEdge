<?php

use App\Filament\Pages\Leaderboard;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterPerformanceSnapshot;
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
