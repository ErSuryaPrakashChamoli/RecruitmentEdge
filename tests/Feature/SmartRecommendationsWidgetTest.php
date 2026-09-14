<?php

use App\Enums\RequisitionStatus;
use App\Filament\Widgets\SmartRecommendationsWidget;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('smart recommendations stay visible without AI and render the gathered database facts', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    RecruitmentRequisition::factory()->create([
        'code' => 'REQ-RISK-FACT',
        'status' => RequisitionStatus::Open,
        'openings' => 2,
        'opening_date' => now(),
    ]);

    expect(SmartRecommendationsWidget::canView())->toBeTrue();

    Livewire::test(SmartRecommendationsWidget::class)
        ->call('generate')
        ->assertSee('AI narration is not available')
        ->assertSee('Positions at Risk')
        ->assertSee('REQ-RISK-FACT')
        ->assertSee('Turn-up')
        ->assertSee('Funnel');
});

test('a viewer without ai.query still gets the facts', function (): void {
    Role::findOrCreate('no-ai')->syncPermissions(['performance.view']);

    $user = User::factory()->create();
    $user->assignRole('no-ai');
    actingAs($user);

    Livewire::test(SmartRecommendationsWidget::class)
        ->call('generate')
        ->assertSet('result.narrative', null)
        ->assertSee('Positions at Risk');
});
