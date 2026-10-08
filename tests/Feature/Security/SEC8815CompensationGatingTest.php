<?php

use App\Enums\OfferStatus;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ListRecruiterIncentiveCalculations;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ViewRecruiterIncentiveCalculation;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\Role;
use App\Models\User;
use App\Services\IncentiveStatementService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * SEC-88-15 (owner decision 2026-10-01, A): offer letters, the incentive export and incentive
 * statements show pay, so they need compensation.view — except a person's own statement. Every
 * default staff role holds compensation.view; the gap mattered for custom roles.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);

    $role = Role::create(['name' => 'pay-blind reviewer']);
    $role->givePermissionTo(['offers.manage', 'reports.export', 'incentives.view', 'incentives.approve', 'candidates.viewAny', 'hierarchy.view-all']);
    $this->ownEmployee = Employee::factory()->create();
    $this->payBlind = User::factory()->create(['employee_id' => $this->ownEmployee->id])->assignRole($role);
    $this->chro = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->offer = Offer::factory()->create(['status' => OfferStatus::Initiated, 'offered_ctc' => 1500000]);
});

test('the offer letter (full CTC) is offered only with compensation.view', function (): void {
    $this->actingAs($this->payBlind, 'web');
    Livewire::test(EditOffer::class, ['record' => $this->offer->getKey()])->assertActionHidden('downloadOfferLetter');

    $this->actingAs($this->chro, 'web');
    Livewire::test(EditOffer::class, ['record' => $this->offer->getKey()])->assertActionVisible('downloadOfferLetter');
});

test('the incentive export is offered only with compensation.view', function (): void {
    $this->actingAs($this->payBlind, 'web');
    Livewire::test(ListRecruiterIncentiveCalculations::class)->assertActionHidden(TestAction::make('export')->table());

    $this->actingAs($this->chro, 'web');
    Livewire::test(ListRecruiterIncentiveCalculations::class)->assertActionVisible(TestAction::make('export')->table());
});

test('someone else\'s incentive statement needs compensation.view; your own does not', function (): void {
    $service = app(IncentiveStatementService::class);
    $other = Employee::factory()->create();
    $othersCalculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $other->id]);
    $ownCalculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $this->ownEmployee->id]);

    expect($service->canDownloadFor($this->payBlind, $other))->toBeFalse()
        ->and($service->canDownloadFor($this->payBlind, $this->ownEmployee))->toBeTrue()
        ->and($service->canDownloadFor($this->chro, $other))->toBeTrue();

    $this->actingAs($this->payBlind, 'web');
    Livewire::test(ViewRecruiterIncentiveCalculation::class, ['record' => $othersCalculation->getKey()])->assertActionHidden('downloadStatement');
    Livewire::test(ViewRecruiterIncentiveCalculation::class, ['record' => $ownCalculation->getKey()])->assertActionVisible('downloadStatement');

    Livewire::test(ListRecruiterIncentiveCalculations::class)
        ->callAction('downloadPeriodStatement', data: ['employee_id' => $other->id, 'month' => now()->format('Y-m')])
        ->assertNoFileDownloaded();
});
