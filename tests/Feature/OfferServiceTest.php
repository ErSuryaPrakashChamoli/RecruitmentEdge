<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\OfferStatus;
use App\Events\OfferAccepted;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferStatusHistory;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\OfferService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->service = app(OfferService::class);
});

function employeeWithRole(string $role): Employee
{
    $employee = Employee::factory()->create();
    User::factory()->create(['employee_id' => $employee->id])->assignRole($role);

    return $employee;
}

test('an offer moves through its full lifecycle and syncs the application stage', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);
    $offer = Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Draft]);

    $this->service->moveTo($offer, OfferStatus::Initiated);
    expect($offer->refresh()->status)->toBe(OfferStatus::Initiated)
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::OfferInitiated);

    $this->service->moveTo($offer, OfferStatus::Released, employeeWithRole('manager'));
    expect($application->refresh()->current_stage)->toBe(CandidateStage::OfferReleased);

    $this->service->moveTo($offer, OfferStatus::Accepted);
    expect($offer->refresh()->status)->toBe(OfferStatus::Accepted)
        ->and($offer->accepted_at)->not->toBeNull()
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::OfferAccepted)
        ->and($offer->statusHistory()->count())->toBe(3);
});

test('an invalid offer transition is rejected', function (): void {
    $offer = Offer::factory()->create(['status' => OfferStatus::Draft]);

    $this->service->moveTo($offer, OfferStatus::Accepted);
})->throws(DomainException::class);

test('rejecting an offer requires a reason and rejects the application', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);
    $offer = Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Released]);

    expect(fn () => $this->service->moveTo($offer, OfferStatus::Rejected))
        ->toThrow(DomainException::class);

    $reason = RecruitmentRejectionReason::factory()->create();
    $this->service->moveTo($offer, OfferStatus::Rejected, rejectionReason: $reason);

    expect($application->refresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($application->rejection_reason_id)->toBe($reason->id);
});

test('accepting an offer dispatches OfferAccepted', function (): void {
    Event::fake();

    $offer = Offer::factory()->create(['status' => OfferStatus::Released]);

    $this->service->moveTo($offer, OfferStatus::Accepted);

    Event::assertDispatched(OfferAccepted::class, fn (OfferAccepted $event) => $event->offer->is($offer));
});

test('accepting an offer creates a candidate joining record automatically', function (): void {
    $offer = Offer::factory()->create([
        'status' => OfferStatus::Released,
        'expected_joining_date' => now()->addWeeks(3),
    ]);

    $this->service->moveTo($offer, OfferStatus::Accepted);

    $joining = CandidateJoining::query()->where('candidate_application_id', $offer->candidate_application_id)->first();

    expect($joining)->not->toBeNull()
        ->and($joining->offer_id)->toBe($offer->id)
        ->and($joining->expected_doj->toDateString())->toBe($offer->expected_joining_date->toDateString());
});

test('releasing an offer notifies the recruiter', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $offer = Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Initiated]);

    $this->service->moveTo($offer, OfferStatus::Released, employeeWithRole('manager'));

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'])->toBe('[Offers] Offer released');
});

test('releasing an offer requires an actor with offers.release', function (): void {
    $offer = Offer::factory()->create(['status' => OfferStatus::Initiated]);

    expect(fn () => $this->service->moveTo($offer, OfferStatus::Released, employeeWithRole('recruiter')))
        ->toThrow(DomainException::class, 'offers.release')
        ->and(fn () => $this->service->moveTo($offer, OfferStatus::Released))
        ->toThrow(DomainException::class, 'offers.release');

    expect($offer->refresh()->status)->toBe(OfferStatus::Initiated)
        ->and($offer->statusHistory()->count())->toBe(0);
});

test('creating an offer through the service writes its initial status history row', function (): void {
    $actor = employeeWithRole('recruiter');
    $application = CandidateApplication::factory()->create();

    $offer = $this->service->create([
        'offer_code' => 'OFR-TEST-1',
        'candidate_application_id' => $application->id,
        'offer_date' => now(),
    ], $actor);

    $history = $offer->statusHistory()->sole();

    expect($offer->status)->toBe(OfferStatus::Draft)
        ->and($history->from_status)->toBeNull()
        ->and($history->to_status)->toBe(OfferStatus::Draft)
        ->and($history->changed_by)->toBe($actor->id);
});

test('expireLapsedOffers expires only released offers whose validity date has passed', function (): void {
    $lapsed = Offer::factory()->create(['status' => OfferStatus::Released, 'offer_expiry' => now()->subDay()]);
    $validToday = Offer::factory()->create(['status' => OfferStatus::Released, 'offer_expiry' => now()]);
    $notReleased = Offer::factory()->create(['status' => OfferStatus::Initiated, 'offer_expiry' => now()->subDays(3)]);

    expect($this->service->expireLapsedOffers())->toBe(1)
        ->and($lapsed->refresh()->status)->toBe(OfferStatus::Expired)
        ->and($lapsed->statusHistory()->sole()->to_status)->toBe(OfferStatus::Expired)
        ->and($validToday->refresh()->status)->toBe(OfferStatus::Released)
        ->and($notReleased->refresh()->status)->toBe(OfferStatus::Initiated);
});

test('offer status history rows cannot be updated or deleted', function (): void {
    $offer = Offer::factory()->create(['status' => OfferStatus::Draft]);
    $this->service->moveTo($offer, OfferStatus::Initiated);
    $history = $offer->statusHistory()->sole();

    expect(fn () => $history->update(['remarks' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $history->delete())->toThrow(LogicException::class)
        ->and(OfferStatusHistory::query()->find($history->id)->remarks)->toBeNull();
});
