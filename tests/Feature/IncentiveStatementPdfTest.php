<?php

use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ViewRecruiterIncentiveCalculation;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a user with reports.export can download the incentive statement PDF', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    $calculation = RecruiterIncentiveCalculation::factory()->create();

    Livewire::test(ViewRecruiterIncentiveCalculation::class, ['record' => $calculation->getRouteKey()])
        ->callAction('downloadStatement')
        ->assertFileDownloaded("incentive-statement-{$calculation->id}.pdf");
});
