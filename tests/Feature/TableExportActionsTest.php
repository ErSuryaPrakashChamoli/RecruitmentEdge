<?php

use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a recruiter can see the export action on the candidates table', function (): void {
    $user = User::factory()->create();
    $user->assignRole('recruiter');
    actingAs($user);

    Livewire::test(ListCandidates::class)
        ->assertActionVisible(TestAction::make('export')->table());
});

test('a user without the reports.export permission cannot see the export action', function (): void {
    Role::findOrCreate('no-export')->syncPermissions(['candidates.viewAny']);

    $user = User::factory()->create();
    $user->assignRole('no-export');
    actingAs($user);

    Livewire::test(ListCandidates::class)
        ->assertActionHidden(TestAction::make('export')->table());
});

test('the export action is visible on the offers table for a manager', function (): void {
    $user = User::factory()->create();
    $user->assignRole('manager');
    actingAs($user);

    Livewire::test(ListOffers::class)
        ->assertActionVisible(TestAction::make('export')->table());
});
