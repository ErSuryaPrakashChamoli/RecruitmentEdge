<?php

use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\OfferStatus;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\User;
use App\Services\InterviewFeedbackService;
use App\Services\OfferService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->managerA = Employee::factory()->create();
    $this->userA = User::factory()->create(['employee_id' => $this->managerA->id])->assignRole('manager');
    $this->userB = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('manager');
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->managerA->id, 'current_stage' => CandidateStage::OfferReleased]);
});

test('a releaser outside the hierarchy cannot release another team\'s offer revision', function (): void {
    $offer = Offer::factory()->create(['candidate_application_id' => $this->application->id, 'status' => OfferStatus::Released]);
    $revision = app(OfferService::class)->requestRevision($offer, ['offered_ctc' => 1500000], 'Counter-offer', $this->userA);

    expect($this->userB->can('offers.release'))->toBeTrue()
        ->and(fn () => app(OfferService::class)->releaseRevision($revision, $this->userB))->toThrow(DomainException::class, 'offers.release')
        ->and($offer->fresh()->offered_ctc)->not->toEqual('1500000.00');
});

test('a manager outside the hierarchy cannot correct another team\'s interview feedback', function (): void {
    $interviewer = Employee::factory()->reportingTo($this->managerA)->create();
    $interview = Interview::factory()->create(['candidate_application_id' => $this->application->id, 'interviewer_id' => $interviewer->id, 'status' => InterviewStatus::Scheduled]);
    $feedback = app(InterviewFeedbackService::class)->submit($interview, ['recommendation' => 'recommend', 'feedback' => 'Good'], $this->userA);

    expect(fn () => app(InterviewFeedbackService::class)->correct($feedback, ['recommendation' => 'neutral', 'feedback' => 'x'], 'reason', $this->userB))->toThrow(DomainException::class, 'not allowed');
});

test('no Phase 8.3 audit row carries a compensation figure', function (): void {
    $offer = Offer::factory()->create(['candidate_application_id' => CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected])->id, 'status' => OfferStatus::Draft, 'offered_ctc' => 4242424, 'fixed_salary' => 3131313]);
    $offer->update(['offered_ctc' => 5353535]);
    lifecycleFixture(fn () => $offer->forceFill(['status' => OfferStatus::Released])->save());
    app(OfferService::class)->releaseRevision(app(OfferService::class)->requestRevision($offer->fresh(), ['offered_ctc' => 6464646], 'Counter-offer', User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro')), User::query()->latest('id')->first());

    $audit = json_encode(AuditLog::query()->get(['old_values', 'changes'])->toArray());

    foreach (['4242424', '3131313', '5353535', '6464646'] as $figure) {
        expect(str_contains($audit, $figure))->toBeFalse("audit log contains {$figure}");
    }
});
