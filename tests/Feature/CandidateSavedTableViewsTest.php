<?php

use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Models\SavedTableView;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('chro');
    actingAs($this->user);
});

test('the candidates list can save and load a named table view', function (): void {
    Livewire::test(ListCandidates::class)
        ->set('tableSearch', 'Priya')
        ->callAction(TestAction::make('saveTableView'), data: ['name' => 'Priya search', 'is_default' => false]);

    $view = SavedTableView::query()->where('user_id', $this->user->id)->sole();

    expect($view->resource)->toBe(ListCandidates::class)
        ->and($view->search)->toBe('Priya');

    Livewire::test(ListCandidates::class)
        ->callAction(TestAction::make('loadTableView'), data: ['view_id' => $view->id])
        ->assertSet('tableSearch', 'Priya');
});
