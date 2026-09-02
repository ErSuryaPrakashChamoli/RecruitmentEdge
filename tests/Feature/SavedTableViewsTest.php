<?php

use App\Filament\Resources\CandidateApplications\Pages\ListCandidateApplications;
use App\Models\CandidateApplication;
use App\Models\Employee;
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

test('a user can save the current table filter state as a named view', function (): void {
    Livewire::test(ListCandidateApplications::class)
        ->set('tableFilters', ['status' => ['value' => 'active']])
        ->callAction(TestAction::make('saveTableView'), data: ['name' => 'My Offer Stage Candidates', 'is_default' => false]);

    $view = SavedTableView::query()->where('user_id', $this->user->id)->first();

    expect($view)->not->toBeNull()
        ->and($view->name)->toBe('My Offer Stage Candidates')
        ->and($view->resource)->toBe(ListCandidateApplications::class)
        ->and($view->filters)->toBe(['status' => ['value' => 'active']]);
});

test('a user can load a saved view, restoring its filter state', function (): void {
    $view = SavedTableView::factory()->create([
        'user_id' => $this->user->id,
        'resource' => ListCandidateApplications::class,
        'filters' => ['status' => ['value' => 'rejected']],
        'search' => 'Jane',
    ]);

    Livewire::test(ListCandidateApplications::class)
        ->callAction(TestAction::make('loadTableView'), data: ['view_id' => $view->id])
        ->assertSet('tableFilters', ['status' => ['value' => 'rejected']])
        ->assertSet('tableSearch', 'Jane');
});

test('a user can rename a saved view', function (): void {
    $view = SavedTableView::factory()->create([
        'user_id' => $this->user->id,
        'resource' => ListCandidateApplications::class,
        'name' => 'Old Name',
    ]);

    Livewire::test(ListCandidateApplications::class)
        ->callAction(TestAction::make('renameTableView'), data: ['view_id' => $view->id, 'name' => 'New Name']);

    expect($view->fresh()->name)->toBe('New Name');
});

test('a user can delete a saved view', function (): void {
    $view = SavedTableView::factory()->create([
        'user_id' => $this->user->id,
        'resource' => ListCandidateApplications::class,
    ]);

    Livewire::test(ListCandidateApplications::class)
        ->callAction(TestAction::make('deleteTableView'), data: ['view_id' => $view->id]);

    expect(SavedTableView::query()->find($view->id))->toBeNull();
});

test('a saved view from one user never appears for another user', function (): void {
    $otherUser = User::factory()->create();
    SavedTableView::factory()->create([
        'user_id' => $otherUser->id,
        'resource' => ListCandidateApplications::class,
        'name' => "Someone Else's View",
    ]);

    Livewire::test(ListCandidateApplications::class)
        ->assertActionHidden(TestAction::make('loadTableView'));
});

test('loading a saved view does not bypass hierarchy scoping', function (): void {
    $manager = Employee::factory()->create();
    $ownRecruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $visible = CandidateApplication::factory()->create(['recruiter_id' => $ownRecruiter->id]);
    $visible->candidate->update(['full_name' => 'Visible Candidate']);

    $hidden = CandidateApplication::factory()->create(['recruiter_id' => $outsider->id]);
    $hidden->candidate->update(['full_name' => 'Hidden Candidate']);

    $managerUser = User::factory()->create(['employee_id' => $manager->id]);
    $managerUser->assignRole('manager');

    $view = SavedTableView::factory()->create([
        'user_id' => $managerUser->id,
        'resource' => ListCandidateApplications::class,
        'filters' => [],
    ]);

    actingAs($managerUser);

    Livewire::test(ListCandidateApplications::class)
        ->callAction(TestAction::make('loadTableView'), data: ['view_id' => $view->id])
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden]);
});
