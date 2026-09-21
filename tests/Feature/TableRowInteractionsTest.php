<?php

use App\Filament\Resources\CandidateApplications\Pages\ViewCandidateApplication;
use App\Filament\Resources\CandidateApplications\RelationManagers\OffersRelationManager;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Tables\Enums\RecordActionsPosition;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $recruiter = Employee::factory()->create();
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $this->offer = Offer::factory()->create(['candidate_application_id' => $this->application->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);
});

test('every resource with an edit page also has a view page for row clicks to open', function (): void {
    $resourcesWithoutViewPage = collect(Filament::getPanel('admin')->getResources())
        ->filter(fn (string $resource): bool => $resource::hasPage('edit') && ! $resource::hasPage('view'))
        ->values();

    expect($resourcesWithoutViewPage)->toBeEmpty();
});

test('resource list rows open the view page and render actions in the first column', function (): void {
    $table = Livewire::test(ListOffers::class)->instance()->getTable();

    expect($table->getRecordActionsPosition())->toBe(RecordActionsPosition::BeforeColumns)
        ->and($table->getRecordUrl($this->offer))->toBe(OfferResource::getUrl('view', ['record' => $this->offer]));
});

test('relation manager rows open the related record view page', function (): void {
    $table = Livewire::test(OffersRelationManager::class, ['ownerRecord' => $this->application, 'pageClass' => ViewCandidateApplication::class])
        ->instance()
        ->getTable();

    expect($table->getRecordActionsPosition())->toBe(RecordActionsPosition::BeforeColumns)
        ->and($table->getRecordUrl($this->offer))->toBe(OfferResource::getUrl('view', ['record' => $this->offer]));
});

test('a generated view page renders the record read-only', function (): void {
    $this->get(OfferResource::getUrl('view', ['record' => $this->offer]))
        ->assertSuccessful()
        ->assertSee($this->offer->offer_code);
});
