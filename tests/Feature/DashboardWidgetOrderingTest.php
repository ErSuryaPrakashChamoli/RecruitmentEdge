<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\RecruitmentFunnelWidget;
use App\Filament\Widgets\TodaysRecruitmentPulse;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a plain recruiter with no reports sees action-oriented widgets before the funnel', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    $widgets = (new Dashboard)->getWidgets();

    $pulsePosition = array_search(TodaysRecruitmentPulse::class, $widgets, true);
    $funnelPosition = array_search(RecruitmentFunnelWidget::class, $widgets, true);

    expect($pulsePosition)->not->toBeFalse()
        ->and($funnelPosition)->not->toBeFalse()
        ->and($pulsePosition)->toBeLessThan($funnelPosition);
});

test('a manager with reports sees the funnel before the daily pulse', function (): void {
    $manager = Employee::factory()->create();
    Employee::factory()->reportingTo($manager)->create();

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');
    actingAs($user);

    $widgets = (new Dashboard)->getWidgets();

    $pulsePosition = array_search(TodaysRecruitmentPulse::class, $widgets, true);
    $funnelPosition = array_search(RecruitmentFunnelWidget::class, $widgets, true);

    expect($funnelPosition)->toBeLessThan($pulsePosition);
});

test('every widget class appears exactly once regardless of role', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    $widgets = (new Dashboard)->getWidgets();

    expect($widgets)->toHaveCount(count(array_unique($widgets)));
});
