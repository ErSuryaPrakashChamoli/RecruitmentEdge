<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Events\CandidateJoined;
use App\Filament\Resources\CandidateJoinings\Pages\CreateCandidateJoining;
use App\Filament\Resources\CandidateJoinings\Pages\ListCandidateJoinings;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\User;
use App\Services\CandidateJoiningService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * Phase 8.10 (P810-DI-04): a joining completes the offer chain (application → Selected → offer →
 * Accepted → joining). Staff create one only for an application's accepted offer, the offer is
 * derived, and Mark Joined needs that accepted offer — so a hand-made joining can no longer move
 * an application to Joined, price an incentive or count a hire. The recovery for an accepted offer
 * whose joining is missing stays.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $managerEmployee = Employee::factory()->create();
    $this->recruiterEmployee = Employee::factory()->create(['reports_to_id' => $managerEmployee->id]);
    $this->recruiter = User::factory()->create(['employee_id' => $this->recruiterEmployee->id])->assignRole('recruiter');
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiterEmployee->id, 'current_stage' => CandidateStage::OfferAccepted]);
    $this->service = app(CandidateJoiningService::class);
});

function acceptedOfferFor(CandidateApplication $application): Offer
{
    return Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Accepted, 'expected_joining_date' => now()->addWeeks(3)]);
}

test('a joining is created for the application\'s accepted offer, derived, attributed and audited', function (): void {
    $offer = acceptedOfferFor($this->application);

    $joining = $this->service->createForApplication($this->application, $this->recruiter, ['remarks' => 'Recovered after a failed acceptance']);

    expect($joining->offer_id)->toBe($offer->id)
        ->and($joining->status)->toBe(JoiningStatus::Expected)
        ->and($joining->expected_doj->toDateString())->toBe(now()->addWeeks(3)->toDateString())
        ->and($joining->created_by)->toBe($this->recruiterEmployee->id)
        ->and($joining->remarks)->toBe('Recovered after a failed acceptance')
        ->and(AuditLog::query()->where('action', 'joining_created_for_accepted_offer')->sole()->getAttribute('changes'))->toBe(['offer_id' => $offer->id, 'by_user_id' => $this->recruiter->id]);
});

test('a joining is refused where the offer chain is incomplete', function (Closure $arrange, string $message): void {
    $arrange($this->application);

    expect(fn () => $this->service->createForApplication($this->application->fresh(), $this->recruiter))->toThrow(DomainException::class, $message)
        ->and(CandidateJoining::query()->where('candidate_application_id', $this->application->id)->count())->toBeLessThanOrEqual(1)
        ->and(AuditLog::query()->where('action', 'joining_created_for_accepted_offer')->exists())->toBeFalse();
})->with([
    'no offer' => [fn (CandidateApplication $application) => null, 'accepted offer'],
    'an offer not yet accepted' => [fn (CandidateApplication $application) => Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Released]), 'accepted offer'],
    'a closed application' => [fn (CandidateApplication $application) => acceptedOfferFor($application) && $application->forceFill(['status' => ApplicationStatus::Rejected])->saveQuietly(), 'active application'],
    'a joining already recorded' => [fn (CandidateApplication $application) => CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'offer_id' => acceptedOfferFor($application)->id]), 'already has a joining record'],
]);

test('only a joining.confirm holder over the application\'s hierarchy may create one', function (Closure $actor): void {
    acceptedOfferFor($this->application);

    expect(fn () => $this->service->createForApplication($this->application, $actor()))->toThrow(DomainException::class, 'cannot create')
        ->and(CandidateJoining::query()->exists())->toBeFalse();
})->with([
    'a recruiter of another team' => [fn () => User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter')],
    'a user without joining.confirm' => [fn () => User::factory()->create(['employee_id' => Employee::factory()->create()->id])],
]);

test('the New joining page creates through the service and ignores a submitted offer', function (): void {
    $offer = acceptedOfferFor($this->application);
    $otherOffer = Offer::factory()->create(['status' => OfferStatus::Accepted]);
    actingAs($this->recruiter);

    Livewire::test(CreateCandidateJoining::class)
        ->fillForm(['candidate_application_id' => $this->application->id, 'documents_status' => 'pending'])
        ->set('data.offer_id', $otherOffer->id)
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CandidateJoining::query()->where('candidate_application_id', $this->application->id)->sole()->offer_id)->toBe($offer->id);
});

test('the New joining page refuses an application without an accepted offer', function (): void {
    Offer::factory()->create(['candidate_application_id' => $this->application->id, 'status' => OfferStatus::Released]);
    actingAs($this->recruiter);

    Livewire::test(CreateCandidateJoining::class)
        ->fillForm(['candidate_application_id' => $this->application->id, 'expected_doj' => now()->addWeek()->toDateString(), 'documents_status' => 'pending'])
        ->call('create')
        ->assertNotified('Joining record not created');

    expect(CandidateJoining::query()->exists())->toBeFalse();
});

test('Mark Joined refuses a joining without the application\'s accepted offer: no stage, incentive or hire', function (Closure $offerId): void {
    Event::fake([CandidateJoined::class]);
    RecruitmentIncentiveRule::factory()->fixed(1500)->create(['effective_from' => now()->subYear()]);
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $this->application->id, 'offer_id' => $offerId($this->application), 'status' => JoiningStatus::Confirmed]);
    actingAs($this->recruiter);

    Livewire::test(ListCandidateJoinings::class)
        ->callAction(TestAction::make('markJoined')->table($joining))
        ->assertNotified('Joining could not be updated');

    expect($joining->fresh()->status)->toBe(JoiningStatus::Confirmed)
        ->and($this->application->fresh()->current_stage)->toBe(CandidateStage::OfferAccepted)
        ->and(RecruiterIncentiveCalculation::query()->exists())->toBeFalse();
    Event::assertNotDispatched(CandidateJoined::class);
})->with([
    'no offer (a hand-made joining)' => [fn (CandidateApplication $application) => null],
    'another application\'s accepted offer' => [fn (CandidateApplication $application) => Offer::factory()->create(['status' => OfferStatus::Accepted])->id],
    'an offer the candidate rejected' => [fn (CandidateApplication $application) => Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Rejected])->id],
]);

test('Mark Joined on the accepted offer\'s joining still joins, prices and counts the hire', function (): void {
    Event::fake([CandidateJoined::class]);
    RecruitmentIncentiveRule::factory()->fixed(1500)->create(['effective_from' => now()->subYear()]);
    acceptedOfferFor($this->application);
    $joining = $this->service->createForApplication($this->application, $this->recruiter);
    actingAs($this->recruiter);

    Livewire::test(ListCandidateJoinings::class)
        ->callAction(TestAction::make('markJoined')->table($joining))
        ->assertNotified('Candidate marked as joined');

    expect($joining->fresh()->status)->toBe(JoiningStatus::Joined)
        ->and($this->application->fresh()->current_stage)->toBe(CandidateStage::Joined)
        ->and(RecruiterIncentiveCalculation::query()->count())->toBe(1);
    Event::assertDispatched(CandidateJoined::class);
});
