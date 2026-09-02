<?php

use App\Filament\Pages\DashboardQuoteSettings;
use App\Models\Employee;
use App\Models\RecruitmentSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the dashboard greeting reflects IST, not the app\'s UTC timezone', function (): void {
    // 19:00 UTC on a given day is 00:30 IST the *next* day — a "Good evening" bucket in UTC but a
    // "Good morning" bucket in IST. Only a genuinely IST-aware calculation gets this right.
    $this->travelTo(Carbon::parse('2026-01-15 19:00:00', 'UTC'));

    $recruiter = Employee::factory()->create(['first_name' => 'Asha', 'last_name' => 'Rao']);
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');

    actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Good Morning, Asha Rao')
        ->assertDontSee('Good Evening, Asha Rao');
});

test('the dashboard shows the admin-configured quote of the day when one is set', function (): void {
    RecruitmentSetting::put('dashboard.quote_text', 'Great hires start with great follow-through.', group: 'dashboard');
    RecruitmentSetting::put('dashboard.quote_icon', 'heroicon-o-trophy', group: 'dashboard');

    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('recruiter');

    actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Great hires start with great follow-through.');
});

test('the dashboard shows no quote line at all when the admin has not set one', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('recruiter');

    actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Recruitment Command Center')
        ->assertDontSee('Great hires start with great follow-through.');
});

test('an admin can update the quote and icon from the settings page, and it persists via RecruitmentSetting', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(DashboardQuoteSettings::class)
        ->fillForm([
            'quote' => 'Momentum compounds — one follow-up at a time.',
            'icon' => 'heroicon-o-rocket-launch',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(RecruitmentSetting::get('dashboard.quote_text'))->toBe('Momentum compounds — one follow-up at a time.')
        ->and(RecruitmentSetting::get('dashboard.quote_icon'))->toBe('heroicon-o-rocket-launch');
});

test('a user without settings.manage cannot reach the dashboard quote settings page', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('recruiter');

    actingAs($user)
        ->get('/admin/dashboard-quote-settings')
        ->assertForbidden();
});
