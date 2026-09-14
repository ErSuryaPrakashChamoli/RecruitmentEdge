<?php

use App\Filament\Resources\RecruitmentSettings\Pages\ManageRecruitmentConfiguration;
use App\Filament\Resources\RecruitmentSettings\RecruitmentSettingResource;
use App\Models\RecruitmentSetting;
use App\Models\User;
use Database\Seeders\RecruitmentReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the configuration page loads defaults and saves typed values, invalidating the cache', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    expect(RecruitmentSetting::get('candidate_stall_days', 7))->toBe(7);

    Livewire::test(ManageRecruitmentConfiguration::class)
        ->assertSet('data.candidate_stall_days', 7)
        ->assertSet('data.sla_days_offer_to_acceptance', 5)
        ->assertSet('data.interviewer_daily_capacity', 4)
        ->set('data.candidate_stall_days', 10)
        ->set('data.time_to_hire_start_point', 'requisition_opened')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(RecruitmentSetting::get('candidate_stall_days'))->toBe(10)
        ->and(RecruitmentSetting::get('time_to_hire_start_point'))->toBe('requisition_opened')
        ->and(RecruitmentSetting::get('position_risk_min_pipeline_ratio'))->toBe(2.0);
});

test('invalid configuration values are rejected and nothing is saved', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(ManageRecruitmentConfiguration::class)
        ->set('data.candidate_stall_days', 0)
        ->set('data.time_to_hire_start_point', 'bogus')
        ->call('save')
        ->assertHasFormErrors(['candidate_stall_days', 'time_to_hire_start_point']);

    expect(RecruitmentSetting::query()->where('key', 'candidate_stall_days')->exists())->toBeFalse();
});

test('users without settings.manage cannot open the configuration page', function (): void {
    $user = User::factory()->create();
    $user->assignRole('recruiter');

    actingAs($user)->get(RecruitmentSettingResource::getUrl('configure'))->assertForbidden();
});

test('the reference data seeder seeds every setting default without overwriting admin changes', function (): void {
    $this->seed(RecruitmentReferenceDataSeeder::class);

    expect(RecruitmentSetting::query()->count())->toBe(count(RecruitmentSetting::DEFINITIONS))
        ->and(RecruitmentSetting::get('sla_days_selection_to_joining'))->toBe(30);

    RecruitmentSetting::put('sla_days_selection_to_joining', 45, 'int', 'sla');

    $this->seed(RecruitmentReferenceDataSeeder::class);

    expect(RecruitmentSetting::get('sla_days_selection_to_joining'))->toBe(45)
        ->and(RecruitmentSetting::query()->count())->toBe(count(RecruitmentSetting::DEFINITIONS));
});
