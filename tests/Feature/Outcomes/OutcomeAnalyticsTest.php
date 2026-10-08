<?php

use App\Enums\OutcomeResult;
use App\Enums\OutcomeSampleBand;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;
use App\Filament\Pages\OutcomeDashboard;
use App\Filament\Resources\HiringOutcomes\HiringOutcomeResource;
use App\Filament\Resources\HiringOutcomes\Pages\ViewHiringOutcome;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Outcomes\OutcomeAnalyticsService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->hr = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->requisition = RecruitmentRequisition::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function outcomeAnalyticsRecord(OutcomeType $type, OutcomeResult $result, RecruitmentRequisition $requisition, array $attributes = []): HiringOutcome
{
    return HiringOutcome::factory()->create([
        'outcome_type' => $type,
        'category' => $type->category(),
        'result' => $result,
        'state' => $result->isObserved() ? OutcomeState::Observed : OutcomeState::Unknown,
        'requisition_id' => $requisition->id,
        'candidate_application_id' => CandidateApplication::factory()->create(['requisition_id' => $requisition->id])->id,
        ...$attributes,
    ]);
}

function outcomeAnalyticsReport(User $user, array $filters = []): array
{
    return app(OutcomeAnalyticsService::class)->report($user, $filters)['metrics'];
}

test('the join rate uses final joining outcomes only; pending joinings are shown separately and void outcomes are left out', function (): void {
    foreach ([OutcomeType::Joined, OutcomeType::Joined, OutcomeType::Joined, OutcomeType::NoShow] as $type) {
        outcomeAnalyticsRecord($type, OutcomeResult::Occurred, $this->requisition);
    }
    outcomeAnalyticsRecord(OutcomeType::Dropout, OutcomeResult::Occurred, $this->requisition, ['state' => OutcomeState::Void]);
    CandidateJoining::factory()->create(['candidate_application_id' => CandidateApplication::factory()->create(['requisition_id' => $this->requisition->id])->id]);

    $joining = outcomeAnalyticsReport($this->hr)['joining'];

    expect($joining['value'])->toEqual(75.0)
        ->and($joining['sample_size'])->toBe(4)
        ->and($joining['counts'])->toMatchArray(['joined' => 3, 'no_show' => 1, 'dropout' => 0, 'pending' => 1])
        ->and($joining['unknown'])->toBe(1)
        ->and($joining['band'])->toBe(OutcomeSampleBand::Limited)
        ->and($joining['definition'])->toContain('joining record')
        ->and($joining['population'])->toContain('in the period');
});

test('a rate is withheld below three outcomes while the counts still show', function (): void {
    outcomeAnalyticsRecord(OutcomeType::Joined, OutcomeResult::Occurred, $this->requisition);
    outcomeAnalyticsRecord(OutcomeType::NoShow, OutcomeResult::Occurred, $this->requisition);

    $joining = outcomeAnalyticsReport($this->hr)['joining'];

    expect($joining['value'])->toBeNull()
        ->and($joining['band'])->toBe(OutcomeSampleBand::Insufficient)
        ->and($joining['counts']['joined'])->toBe(1);
});

test('the period filter uses when each outcome happened', function (): void {
    outcomeAnalyticsRecord(OutcomeType::Joined, OutcomeResult::Occurred, $this->requisition, ['observed_at' => now()->subYears(2)]);
    outcomeAnalyticsRecord(OutcomeType::Joined, OutcomeResult::Occurred, $this->requisition);

    expect(outcomeAnalyticsReport($this->hr)['joining']['counts']['joined'])->toBe(1)
        ->and(outcomeAnalyticsReport($this->hr, ['from' => now()->subYears(3)->toDateString()])['joining']['counts']['joined'])->toBe(2);
});

test('offer acceptance divides accepted by decided offers released in the period and reports undecided ones', function (): void {
    foreach (['accepted', 'accepted', 'rejected', 'pending'] as $index => $status) {
        $offer = Offer::factory()->create();
        outcomeAnalyticsRecord(OutcomeType::OfferReleased, OutcomeResult::Occurred, $this->requisition, ['offer_id' => $offer->id]);

        if ($status !== 'pending') {
            outcomeAnalyticsRecord($status === 'accepted' ? OutcomeType::OfferAccepted : OutcomeType::OfferRejected, OutcomeResult::Occurred, $this->requisition, ['offer_id' => $offer->id]);
        }
    }

    $offers = outcomeAnalyticsReport($this->hr)['offers'];

    expect($offers['value'])->toEqual(66.7)
        ->and($offers['sample_size'])->toBe(3)
        ->and($offers['counts'])->toMatchArray(['released' => 4, 'offer_accepted' => 2, 'offer_rejected' => 1, 'awaiting_decision' => 1]);
});

test('time to hire reports the median of measured joins and counts unknown ones apart', function (): void {
    foreach ([10, 20, 40] as $days) {
        outcomeAnalyticsRecord(OutcomeType::TimeToHire, OutcomeResult::Measured, $this->requisition, ['value' => $days, 'unit' => 'days']);
    }
    outcomeAnalyticsRecord(OutcomeType::TimeToHire, OutcomeResult::Unknown, $this->requisition, ['unit' => 'days']);

    $timeToHire = outcomeAnalyticsReport($this->hr)['time_to_hire'];

    expect($timeToHire['value'])->toEqual(20.0)
        ->and($timeToHire['average'])->toEqual(23.3)
        ->and($timeToHire['sample_size'])->toBe(3)
        ->and($timeToHire['unknown'])->toBe(1);
});

test('source to join groups final outcomes by source and shows a rate only with enough history', function (): void {
    $referral = CandidateSource::factory()->create(['name' => 'Referral']);
    $portal = CandidateSource::factory()->create(['name' => 'Job portal']);

    foreach ([[$referral, OutcomeType::Joined], [$referral, OutcomeType::Joined], [$referral, OutcomeType::NoShow], [$portal, OutcomeType::Joined]] as [$source, $type]) {
        $application = CandidateApplication::factory()->create(['requisition_id' => $this->requisition->id, 'candidate_id' => Candidate::factory()->create(['source_id' => $source->id])->id]);
        outcomeAnalyticsRecord($type, OutcomeResult::Occurred, $this->requisition, ['candidate_application_id' => $application->id]);
    }

    $sources = collect(outcomeAnalyticsReport($this->hr)['source_to_join']['sources'])->keyBy('source');

    expect($sources['Referral'])->toMatchArray(['joined' => 2, 'no_show' => 1, 'sample_size' => 3, 'rate' => 66.7])
        ->and($sources['Job portal'])->toMatchArray(['joined' => 1, 'sample_size' => 1, 'rate' => null, 'band' => OutcomeSampleBand::Insufficient])
        ->and(outcomeAnalyticsReport($this->hr, ['source_id' => $portal->id])['joining']['counts']['joined'])->toBe(1);
});

test('status observations leave not-observed hires out of the rate and count checkpoints not yet due', function (): void {
    $snapshots = collect(range(1, 5))->map(fn () => HiringOutcomeSnapshot::factory()->create(['requisition_id' => $this->requisition->id, 'joined_on' => now()->subDays(40)->toDateString()]));
    HiringOutcomeSnapshot::factory()->create(['requisition_id' => $this->requisition->id, 'joined_on' => now()->subDays(5)->toDateString()]);

    foreach ([OutcomeResult::Active, OutcomeResult::Active, OutcomeResult::Inactive, OutcomeResult::SeparatedBeforeCheckpoint, OutcomeResult::NotObserved] as $index => $result) {
        outcomeAnalyticsRecord(OutcomeType::StatusObserved30d, $result, $this->requisition, ['hiring_outcome_snapshot_id' => $snapshots[$index]->id]);
    }

    $checkpoint = collect(outcomeAnalyticsReport($this->hr)['status_observations']['checkpoints'])->firstWhere('type', OutcomeType::StatusObserved30d);

    expect($checkpoint)->toMatchArray(['active' => 2, 'inactive' => 1, 'separated' => 1, 'not_observed' => 1, 'not_yet_due' => 1, 'awaiting_evaluation' => 0, 'sample_size' => 4, 'active_rate' => 50.0]);
});

test('a manager only sees outcomes of requisitions in their hierarchy', function (): void {
    $managerA = Employee::factory()->create();
    $userA = User::factory()->create(['employee_id' => $managerA->id])->assignRole('manager');
    $mine = RecruitmentRequisition::factory()->create(['manager_id' => $managerA->id]);
    outcomeAnalyticsRecord(OutcomeType::Joined, OutcomeResult::Occurred, $mine);
    $theirs = outcomeAnalyticsRecord(OutcomeType::Joined, OutcomeResult::Occurred, $this->requisition);

    expect(outcomeAnalyticsReport($userA)['joining']['counts']['joined'])->toBe(1)
        ->and(outcomeAnalyticsReport($userA, ['requisition_id' => $this->requisition->id])['joining']['counts']['joined'])->toBe(0)
        ->and(outcomeAnalyticsReport($this->hr)['joining']['counts']['joined'])->toBe(2);

    actingAs($userA);
    get(HiringOutcomeResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
});

test('the dashboard needs outcomes.view and names what is never observed', function (): void {
    actingAs(User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter'));
    get(OutcomeDashboard::getUrl())->assertForbidden();

    actingAs($this->hr);
    get(OutcomeDashboard::getUrl())
        ->assertOk()
        ->assertSee('Not observed — no source data')
        ->assertSee('Performance — no performance or appraisal data is recorded.')
        ->assertSee('Insufficient history');
});

test('correcting an outcome needs a reason, creates an audited version and needs outcomes.manage', function (): void {
    $outcome = outcomeAnalyticsRecord(OutcomeType::Joined, OutcomeResult::Occurred, $this->requisition);

    actingAs($this->hr);
    Livewire::test(ViewHiringOutcome::class, ['record' => $outcome->getRouteKey()])
        ->callAction('correct', ['result' => OutcomeResult::NotApplicable->value, 'reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
    Livewire::test(ViewHiringOutcome::class, ['record' => $outcome->getRouteKey()])
        ->callAction('correct', ['result' => OutcomeResult::NotApplicable->value, 'reason' => 'Recorded against the wrong application']);

    $corrected = HiringOutcome::query()->where('dedupe_key', $outcome->dedupe_key)->current()->first();

    expect($corrected->result)->toBe(OutcomeResult::NotApplicable)
        ->and($corrected->version)->toBe(2)
        ->and(AuditLog::query()->where('action', 'outcome_corrected')->where('auditable_id', $corrected->id)->exists())->toBeTrue();

    $manager = Employee::factory()->create();
    $this->requisition->update(['manager_id' => $manager->id]);
    actingAs(User::factory()->create(['employee_id' => $manager->id])->assignRole('manager'));
    Livewire::test(ViewHiringOutcome::class, ['record' => $corrected->getRouteKey()])
        ->assertActionHidden('correct')
        ->assertActionHidden('void');
});
