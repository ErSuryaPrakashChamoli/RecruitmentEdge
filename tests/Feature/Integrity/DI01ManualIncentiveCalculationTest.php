<?php

use App\Enums\CandidateStage;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ListRecruiterIncentiveCalculations;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\User;
use App\Services\RecruiterIncentiveCalculator;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * Phase 8.10 (P810-DI-01): the manual "Calculate Incentives" action prices a trigger only once its
 * lifecycle event has happened, never twice for one occurrence (whatever the month), only for a
 * holder of incentives.calculate over their own hierarchy, and audits every request. The rule
 * amounts (formulas) are unchanged.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $vpEmployee = Employee::factory()->create();
    $this->recruiter = Employee::factory()->create(['reports_to_id' => $vpEmployee->id]);
    $this->approver = User::factory()->create(['employee_id' => $vpEmployee->id])->assignRole('vp_hr');
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::Interview1]);

    foreach ([IncentiveTriggerEvent::Selection, IncentiveTriggerEvent::OfferAccepted, IncentiveTriggerEvent::Joining] as $event) {
        RecruitmentIncentiveRule::factory()->fixed(1000)->create(['trigger_event' => $event, 'effective_from' => now()->subYear()]);
    }
});

/**
 * Per trigger: the state where its event has not happened, and the state where it has.
 *
 * @return array<string, array{0: IncentiveTriggerEvent, 1: Closure(CandidateApplication): void, 2: Closure(CandidateApplication): void}>
 */
dataset('triggers', fn (): array => [
    'Selection' => [
        IncentiveTriggerEvent::Selection,
        fn (CandidateApplication $application) => null,
        fn (CandidateApplication $application) => $application->forceFill(['current_stage' => CandidateStage::Selected])->saveQuietly(),
    ],
    'Offer accepted' => [
        IncentiveTriggerEvent::OfferAccepted,
        fn (CandidateApplication $application) => Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Released]),
        fn (CandidateApplication $application) => $application->offers()->sole()->forceFill(['status' => OfferStatus::Accepted])->saveQuietly(),
    ],
    'Joining' => [
        IncentiveTriggerEvent::Joining,
        fn (CandidateApplication $application) => CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Confirmed]),
        fn (CandidateApplication $application) => $application->joining()->sole()->forceFill(['status' => JoiningStatus::Joined, 'actual_doj' => now()])->saveQuietly(),
    ],
]);

test('a trigger is priced only once its lifecycle event has happened, and the refusal is audited', function (IncentiveTriggerEvent $event, Closure $notYet, Closure $happened): void {
    $calculator = app(RecruiterIncentiveCalculator::class);
    $notYet($this->application);

    expect(fn () => $calculator->calculateManually($this->application->fresh(), $event, $this->approver))->toThrow(DomainException::class)
        ->and(RecruiterIncentiveCalculation::query()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'incentive_calculation_refused')->sole()->getAttribute('changes'))->toMatchArray(['trigger_event' => $event->value, 'by_user_id' => $this->approver->id]);

    $happened($this->application);
    $calculation = $calculator->calculateManually($this->application->fresh(), $event, $this->approver)->sole();

    expect((float) $calculation->amount)->toBe(1000.0)
        ->and($calculation->employee_id)->toBe($this->recruiter->id)
        ->and(AuditLog::query()->where('action', 'incentive_calculation_requested')->sole()->getAttribute('changes'))->toMatchArray(['trigger_event' => $event->value, 'calculation_ids' => [$calculation->id], 'by_user_id' => $this->approver->id]);
})->with('triggers');

test('an application without a joining record is not priced for joining', function (): void {
    expect(fn () => app(RecruiterIncentiveCalculator::class)->calculateManually($this->application, IncentiveTriggerEvent::Joining, $this->approver))
        ->toThrow(DomainException::class, 'no joining record');
});

test('pricing the same occurrence again in a later month never creates a second calculation', function (): void {
    $this->application->forceFill(['current_stage' => CandidateStage::Selected])->saveQuietly();
    $calculator = app(RecruiterIncentiveCalculator::class);
    $first = $calculator->calculateManually($this->application, IncentiveTriggerEvent::Selection, $this->approver)->sole();

    $this->travel(1)->months();
    $again = $calculator->calculateManually($this->application->fresh(), IncentiveTriggerEvent::Selection, $this->approver)->sole();

    expect(RecruiterIncentiveCalculation::query()->count())->toBe(1)
        ->and($again->id)->toBe($first->id)
        ->and($again->period_start->equalTo($first->period_start))->toBeTrue()
        ->and($again->updated_at->equalTo($first->updated_at))->toBeTrue();
});

test('a joining priced when it was marked joined is not priced again by a later manual run', function (): void {
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $this->application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()->subMonth()]);
    $calculator = app(RecruiterIncentiveCalculator::class);
    $calculator->calculateForJoining($joining);

    $joining->forceFill(['actual_doj' => now()])->saveQuietly();
    $calculator->calculateManually($this->application, IncentiveTriggerEvent::Joining, $this->approver);

    expect(RecruiterIncentiveCalculation::query()->count())->toBe(1);
});

test('only a holder of incentives.calculate over the application\'s hierarchy may calculate', function (Closure $actor): void {
    $this->application->forceFill(['current_stage' => CandidateStage::Selected])->saveQuietly();

    expect(fn () => app(RecruiterIncentiveCalculator::class)->calculateManually($this->application, IncentiveTriggerEvent::Selection, $actor()))
        ->toThrow(DomainException::class, 'cannot calculate')
        ->and(RecruiterIncentiveCalculation::query()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'incentive_calculation_refused')->exists())->toBeTrue();
})->with([
    'the application\'s own recruiter (no incentives.calculate)' => [fn () => User::factory()->create(['employee_id' => test()->recruiter->id])->assignRole('recruiter')],
    'an approver of another hierarchy' => [fn () => User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('vp_hr')],
]);

test('the Calculate Incentives action reports a refusal and prices nothing', function (): void {
    actingAs($this->approver);

    Livewire::test(ListRecruiterIncentiveCalculations::class)
        ->callAction(TestAction::make('calculate'), data: ['candidate_application_id' => $this->application->id, 'trigger_event' => IncentiveTriggerEvent::Selection->value])
        ->assertNotified('Incentives not calculated');

    expect(RecruiterIncentiveCalculation::query()->exists())->toBeFalse();
});
