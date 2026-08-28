<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\OfferStatus;
use App\Events\OfferAccepted;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Offer;
use App\Models\RecruitmentRejectionReason;
use App\Services\OfferService;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->service = app(OfferService::class);
});

test('an offer moves through its full lifecycle and syncs the application stage', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);
    $offer = Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Draft]);

    $this->service->moveTo($offer, OfferStatus::Initiated);
    expect($offer->refresh()->status)->toBe(OfferStatus::Initiated)
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::OfferInitiated);

    $this->service->moveTo($offer, OfferStatus::Released);
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
