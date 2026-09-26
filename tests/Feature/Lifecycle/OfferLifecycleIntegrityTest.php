<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\OfferRevisionStatus;
use App\Enums\OfferStatus;
use App\Events\OfferAccepted;
use App\Events\OfferRevisionReleased;
use App\Filament\Resources\CandidateApplications\Pages\ListCandidateApplications;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\User;
use App\Services\OfferService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
    $this->releaser = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->recruiter = Employee::factory()->reportingTo($this->manager)->create();
    $this->recruiterUser = User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::OfferReleased]);
    $this->offer = Offer::factory()->create(['candidate_application_id' => $this->application->id, 'status' => OfferStatus::Released, 'offered_ctc' => 1000000, 'fixed_salary' => 900000]);
    $this->service = app(OfferService::class);
});

test('an offer can only be raised on a Selected, active application with no other open offer', function (): void {
    $sourced = CandidateApplication::factory()->create(['recruiter_id' => $this->manager->id, 'current_stage' => CandidateStage::Screened]);
    $rejected = CandidateApplication::factory()->create(['recruiter_id' => $this->manager->id, 'current_stage' => CandidateStage::Selected, 'status' => ApplicationStatus::Rejected]);
    $selected = CandidateApplication::factory()->create(['recruiter_id' => $this->manager->id, 'current_stage' => CandidateStage::Selected]);

    expect(fn () => $this->service->create(['offer_code' => 'OFR-T-1', 'candidate_application_id' => $sourced->id, 'offer_date' => now()], $this->manager))->toThrow(DomainException::class, 'once the candidate is Selected')
        ->and(fn () => $this->service->create(['offer_code' => 'OFR-T-2', 'candidate_application_id' => $rejected->id, 'offer_date' => now()], $this->manager))->toThrow(DomainException::class, 'active application')
        ->and(fn () => $this->service->create(['offer_code' => 'OFR-T-3', 'candidate_application_id' => $this->application->id, 'offer_date' => now()], $this->manager))->toThrow(DomainException::class, 'already has an open offer')
        ->and($this->service->create(['offer_code' => 'OFR-T-4', 'candidate_application_id' => $selected->id, 'offer_date' => now()], $this->manager)->status)->toBe(OfferStatus::Draft);

    actingAs($this->managerUser);
    Livewire::test(ListCandidateApplications::class)
        ->assertActionHidden(TestAction::make('raiseOffer')->table($sourced));
});

test('a released offer\'s status, application and terms cannot be edited directly', function (string $attribute, mixed $value): void {
    expect(fn () => $this->offer->update([$attribute => $value]))->toThrow(LogicException::class, 'OfferService');
})->with([
    'status' => ['status', OfferStatus::Accepted],
    'CTC' => ['offered_ctc', 2000000],
    'joining date' => ['expected_joining_date', '2030-01-01'],
    'letter' => ['offer_letter_body', 'Rewritten'],
]);

test('draft terms stay editable, and the released terms are locked in the form', function (): void {
    $draft = Offer::factory()->create(['candidate_application_id' => CandidateApplication::factory()->create()->id, 'status' => OfferStatus::Draft]);
    $draft->update(['offered_ctc' => 1500000]);
    actingAs($this->managerUser);

    expect($draft->fresh()->offered_ctc)->toEqual('1500000.00');
    Livewire::test(EditOffer::class, ['record' => $this->offer->getRouteKey()])
        ->assertFormFieldIsDisabled('offered_ctc')
        ->assertFormFieldIsDisabled('expected_joining_date')
        ->assertActionHidden('customizeOfferLetter');
});

test('a revision keeps the released terms, needs a reason, and takes effect only when released with release permission', function (): void {
    Event::fake([OfferRevisionReleased::class]);

    expect(fn () => $this->service->requestRevision($this->offer, ['offered_ctc' => 1200000], ' ', $this->managerUser))->toThrow(DomainException::class, 'reason');

    $revision = $this->service->requestRevision($this->offer, ['offered_ctc' => 1200000], 'Matched a competing offer', $this->recruiterUser);

    expect($revision->revision)->toBe(2)
        ->and($revision->status)->toBe(OfferRevisionStatus::Pending)
        ->and($this->offer->fresh()->offered_ctc)->toEqual('1000000.00')
        ->and($this->offer->revisions()->where('revision', 1)->sole()->offered_ctc)->toEqual('1000000.00')
        ->and(fn () => $this->service->requestRevision($this->offer, ['offered_ctc' => 1300000], 'Again', $this->managerUser))->toThrow(DomainException::class, 'already awaiting release')
        ->and(fn () => $this->service->releaseRevision($revision, $this->recruiterUser))->toThrow(DomainException::class, 'offers.release');

    $this->service->releaseRevision($revision, $this->releaser, 'Approved by CHRO');
    $audit = AuditLog::query()->where('action', 'offer_revision_released')->sole();

    expect($this->offer->fresh()->offered_ctc)->toEqual('1200000.00')
        ->and($this->offer->fresh()->status)->toBe(OfferStatus::Released)
        ->and($this->offer->revisions()->pluck('status', 'revision')->map->value->all())->toBe([1 => 'superseded', 2 => 'released'])
        ->and($this->offer->statusHistory()->latest('id')->first()->remarks)->toContain('Revision 2 released: Matched a competing offer')
        ->and($audit->changes['changed_terms'])->toBe(['offered_ctc'])
        ->and(json_encode([$audit->old_values, $audit->changes]))->not->toContain('1200000');
    Event::assertDispatched(OfferRevisionReleased::class, fn ($event) => $event->offerId === $this->offer->id && $event->revision === 2);
});

test('an accepted offer is final and cannot be revised', function (): void {
    lifecycleFixture(fn () => $this->offer->forceFill(['status' => OfferStatus::Accepted])->save());

    expect(fn () => $this->service->requestRevision($this->offer->fresh(), ['offered_ctc' => 1], 'x', $this->managerUser))->toThrow(DomainException::class, 'an accepted offer is final');
});

test('Manager B cannot revise Manager A\'s offer', function (): void {
    $other = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('manager');

    expect(fn () => $this->service->requestRevision($this->offer, ['offered_ctc' => 1], 'x', $other))->toThrow(DomainException::class, 'not allowed');
});

test('compensation changes are audited as changed, never with the figures', function (): void {
    $draft = Offer::factory()->create(['candidate_application_id' => CandidateApplication::factory()->create()->id, 'status' => OfferStatus::Draft, 'offered_ctc' => 555555]);
    $draft->update(['offered_ctc' => 777777, 'remarks' => 'Budget approved']);
    $audits = AuditLog::query()->where('auditable_type', $draft->getMorphClass())->where('auditable_id', $draft->id)->get();

    expect(json_encode($audits->map->only(['old_values', 'changes'])))->not->toContain('555555')->not->toContain('777777')
        ->and($audits->firstWhere('action', 'updated')->changes)->toMatchArray(['offered_ctc' => '[redacted]', 'remarks' => 'Budget approved']);
});

test('accepting creates the joining record in the same transaction and OfferAccepted is only heard after commit', function (): void {
    $heard = [];
    Event::listen(OfferAccepted::class, function (OfferAccepted $event) use (&$heard): void {
        $heard[] = $event->offer->id;
    });

    try {
        DB::transaction(function (): void {
            $this->service->moveTo($this->offer, OfferStatus::Accepted, $this->manager);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect($heard)->toBe([])
        ->and($this->offer->fresh()->status)->toBe(OfferStatus::Released)
        ->and(CandidateJoining::query()->where('candidate_application_id', $this->application->id)->exists())->toBeFalse();

    $this->service->moveTo($this->offer->fresh(), OfferStatus::Accepted, $this->manager);

    expect($heard)->toBe([$this->offer->id])
        ->and(CandidateJoining::query()->where('candidate_application_id', $this->application->id)->sole()->offer_id)->toBe($this->offer->id);
});

test('accepting an offer withdraws any other offer still open on the application', function (): void {
    $legacy = lifecycleFixture(fn () => Offer::factory()->create(['candidate_application_id' => $this->application->id, 'status' => OfferStatus::Draft]));

    $this->service->moveTo($this->offer, OfferStatus::Accepted, $this->manager);

    expect($legacy->fresh()->status)->toBe(OfferStatus::Withdrawn)
        ->and($legacy->statusHistory()->latest('id')->first()->remarks)->toContain("offer {$this->offer->offer_code} was accepted");
});
