<?php

use App\Enums\ApplicationStatus;
use App\Enums\OfferStatus;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Offers\Pages\CreateOffer;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Resources\Offers\RelationManagers\StatusHistoryRelationManager;
use App\Filament\Resources\Offers\Tables\OffersTable;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\SavedTableView;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->recruiter = Employee::factory()->create();
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
    $this->offer = Offer::factory()->create([
        'candidate_application_id' => $this->application->id,
        'status' => OfferStatus::Initiated,
    ]);
});

function actingAsOfferUser(Employee $employee, string $role): User
{
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $user->assignRole($role);
    actingAs($user);

    return $user;
}

test('the release action and Released option are only available with offers.release', function (): void {
    actingAsOfferUser($this->recruiter, 'assistant_manager');

    Livewire::test(ListOffers::class)
        ->assertActionHidden(TestAction::make('releaseOffer')->table($this->offer))
        ->assertActionVisible(TestAction::make('changeStatus')->table($this->offer));

    expect(OffersTable::statusOptionsFor($this->offer))->not->toHaveKey(OfferStatus::Released->value)
        ->toHaveKey(OfferStatus::Withdrawn->value);
});

test('a user with offers.release can release an offer from the table', function (): void {
    actingAsOfferUser($this->recruiter, 'manager');

    expect(OffersTable::statusOptionsFor($this->offer))->toHaveKey(OfferStatus::Released->value);

    Livewire::test(ListOffers::class)
        ->callAction(TestAction::make('releaseOffer')->table($this->offer), data: ['remarks' => 'Approved by manager'])
        ->assertNotified('Offer released');

    expect($this->offer->refresh()->status)->toBe(OfferStatus::Released)
        ->and($this->offer->statusHistory()->first()->remarks)->toBe('Approved by manager');
});

test('a service rule violation while releasing shows a notification instead of an error page', function (): void {
    actingAsOfferUser($this->recruiter, 'manager');
    $this->application->forceFill(['status' => ApplicationStatus::Rejected])->save();

    Livewire::test(ListOffers::class)
        ->callAction(TestAction::make('releaseOffer')->table($this->offer))
        ->assertNotified('Offer status could not be changed');

    expect($this->offer->refresh()->status)->toBe(OfferStatus::Initiated);
});

test('the offer letter PDF can be downloaded from the table and the edit page', function (): void {
    actingAsOfferUser($this->recruiter, 'recruiter');

    Livewire::test(ListOffers::class)
        ->callAction(TestAction::make('downloadOfferLetter')->table($this->offer))
        ->assertFileDownloaded("offer-letter-{$this->offer->offer_code}.pdf");

    Livewire::test(EditOffer::class, ['record' => $this->offer->getRouteKey()])
        ->callAction('downloadOfferLetter')
        ->assertFileDownloaded("offer-letter-{$this->offer->offer_code}.pdf");
});

test('creating an offer from the panel writes its initial status history row', function (): void {
    actingAsOfferUser($this->recruiter, 'recruiter');

    Livewire::test(CreateOffer::class)
        ->fillForm([
            'candidate_application_id' => $this->application->id,
            'offer_date' => now()->toDateString(),
            'offered_ctc' => 750000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $offer = Offer::query()->where('offered_ctc', 750000)->sole();
    $history = $offer->statusHistory()->sole();

    expect($history->from_status)->toBeNull()
        ->and($history->to_status)->toBe(OfferStatus::Draft)
        ->and($history->changed_by)->toBe($this->recruiter->id);
});

test('the offer status history relation manager lists the trail read-only', function (): void {
    actingAsOfferUser($this->recruiter, 'recruiter');
    $history = $this->offer->statusHistory()->create(['from_status' => OfferStatus::Draft, 'to_status' => OfferStatus::Initiated]);

    expect(OfferResource::getRelations())->toContain(StatusHistoryRelationManager::class);

    Livewire::test(StatusHistoryRelationManager::class, ['ownerRecord' => $this->offer, 'pageClass' => EditOffer::class])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$history]);
});

test('the offers list supports saved table views', function (): void {
    $user = actingAsOfferUser($this->recruiter, 'recruiter');

    Livewire::test(ListOffers::class)
        ->set('tableFilters', ['status' => ['value' => OfferStatus::Released->value]])
        ->callAction(TestAction::make('saveTableView'), data: ['name' => 'Released offers', 'is_default' => false]);

    expect(SavedTableView::query()->where('user_id', $user->id)->sole()->resource)->toBe(ListOffers::class);
});

test('offer global search finds offers by code and candidate name within the hierarchy', function (): void {
    $this->application->candidate->update(['full_name' => 'Searchable Offer Candidate']);
    $outsiderOffer = Offer::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => Employee::factory()->create()->id])->id,
    ]);

    actingAsOfferUser($this->recruiter, 'recruiter');

    expect(OfferResource::getGlobalSearchResults($this->offer->offer_code)->pluck('title')->first())->toContain($this->offer->offer_code)
        ->and(OfferResource::getGlobalSearchResults('Searchable Offer Candidate'))->toHaveCount(1)
        ->and(OfferResource::getGlobalSearchResults($outsiderOffer->offer_code))->toBeEmpty();
});
