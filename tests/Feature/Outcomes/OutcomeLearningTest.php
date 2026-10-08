<?php

use App\Enums\MemoryType;
use App\Enums\OutcomeInsightKind;
use App\Enums\OutcomeInsightStatus;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;
use App\Enums\RequirementLevel;
use App\Enums\RoleDnaOrigin;
use App\Filament\Resources\OutcomeInsights\OutcomeInsightResource;
use App\Filament\Resources\OutcomeInsights\Pages\ViewOutcomeInsight;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateSource;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\HiringMemoryRecord;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\OutcomeInsight;
use App\Models\RecruitmentRequisition;
use App\Models\RoleDnaVersion;
use App\Models\User;
use App\Services\Intelligence\RoleDnaService;
use App\Services\Intelligence\TalentSignalService;
use App\Services\Outcomes\OutcomeLearningService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->reviewer = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->designation = Designation::factory()->create(['name' => 'Backend Developer']);
    $this->learning = app(OutcomeLearningService::class);
});

/**
 * A completed hire with a recorded 90-day status observation.
 *
 * @param  array<int, string>  $skills
 */
function outcomeLearningHire(Designation $designation, array $skills, OutcomeResult $result = OutcomeResult::Active): HiringOutcome
{
    $snapshot = HiringOutcomeSnapshot::factory()->create([
        'designation_id' => $designation->id,
        'joined_on' => now()->subDays(100)->toDateString(),
        'facts' => ['skills' => $skills],
    ]);

    return HiringOutcome::factory()->create([
        'outcome_type' => OutcomeType::StatusObserved90d,
        'category' => OutcomeType::StatusObserved90d->category(),
        'result' => $result,
        'hiring_outcome_snapshot_id' => $snapshot->id,
    ]);
}

function outcomeLearningSeedPattern(Designation $designation): void
{
    outcomeLearningHire($designation, ['skill:laravel', 'skill:php']);
    outcomeLearningHire($designation, ['skill:laravel', 'skill:php']);
    outcomeLearningHire($designation, ['skill:laravel', 'skill:docker']);
    outcomeLearningHire($designation, ['skill:php'], OutcomeResult::Inactive);
}

test('no learning insight is produced below three observed hires', function (): void {
    outcomeLearningHire($this->designation, ['skill:laravel']);
    outcomeLearningHire($this->designation, ['skill:laravel']);

    expect($this->learning->refresh()['created'])->toBe(0)
        ->and(OutcomeInsight::query()->count())->toBe(0);
});

test('a skill common among hires observed active becomes a suggestion awaiting review, with its basis, and changes nothing', function (): void {
    outcomeLearningSeedPattern($this->designation);

    $this->learning->refresh();
    $insight = OutcomeInsight::query()->where('subject_key', 'skill:laravel')->sole();

    expect($insight->status)->toBe(OutcomeInsightStatus::Review)
        ->and($insight->kind)->toBe(OutcomeInsightKind::RoleDnaLearning)
        ->and($insight->sample_size)->toBe(4)
        ->and($insight->evidence)->toMatchArray(['observed' => 4, 'observed_active' => 3, 'active_with_skill' => 3])
        ->and($insight->insight)->toContain('3 of the 3 observed active listed Laravel')
        ->and($insight->limitations)->toContain('not a cause')
        ->and($insight->period_start)->not->toBeNull()
        ->and(OutcomeInsight::query()->where('subject_key', 'skill:docker')->exists())->toBeFalse()
        ->and(RoleDnaVersion::query()->count())->toBe(0);
});

test('insight text is observational and names no person', function (): void {
    outcomeLearningSeedPattern($this->designation);
    $this->learning->refresh();

    foreach (OutcomeInsight::query()->get() as $insight) {
        expect(preg_match('/\b(because|causes?|caused|leads? to|results? in|guarantees?|predicts?)\b/i', $insight->insight.' '.$insight->suggested_change))->toBe(0)
            ->and($insight->subject_key)->toStartWith('skill:');
    }
});

test('recalculating is idempotent, keeps decisions and expires open insights that no longer qualify', function (): void {
    outcomeLearningSeedPattern($this->designation);
    $this->learning->refresh();

    expect($this->learning->refresh())->toBe(['created' => 0, 'updated' => 2, 'expired' => 0])
        ->and(OutcomeInsight::query()->count())->toBe(2);

    $php = OutcomeInsight::query()->where('subject_key', 'skill:php')->sole();
    $this->learning->reject($php, $this->reviewer, 'Not relevant to the role');
    HiringOutcome::query()->where('result', OutcomeResult::Active)->get()->each(fn (HiringOutcome $outcome) => $outcome->update(['is_current' => false]));

    expect($this->learning->refresh()['expired'])->toBe(1)
        ->and(OutcomeInsight::query()->where('subject_key', 'skill:laravel')->sole()->status)->toBe(OutcomeInsightStatus::Expired)
        ->and($php->fresh()->status)->toBe(OutcomeInsightStatus::Rejected);
});

test('voided status observations are not learned from', function (): void {
    outcomeLearningSeedPattern($this->designation);
    HiringOutcome::query()->where('result', OutcomeResult::Active)->first()->forceFill(['state' => OutcomeState::Void])->saveQuietly();

    $this->learning->refresh();

    expect(OutcomeInsight::query()->where('subject_key', 'skill:laravel')->exists())->toBeFalse();
});

test('accepting a Role DNA suggestion adds a preferred skill in a new version, and it shows in Role DNA history', function (): void {
    outcomeLearningSeedPattern($this->designation);
    $this->learning->refresh();
    $insight = OutcomeInsight::query()->where('subject_key', 'skill:laravel')->sole();
    $requisition = RecruitmentRequisition::factory()->create(['designation_id' => $this->designation->id, 'skills' => ['PHP']]);

    expect(fn () => $this->learning->accept($insight, $this->reviewer, $requisition, RequirementLevel::Required))->toThrow(DomainException::class, 'preferred or informational');

    $this->learning->accept($insight, $this->reviewer, $requisition, RequirementLevel::Preferred, 'Matches the team stack');
    $dna = collect(app(RoleDnaService::class)->currentVersionFor($requisition)->dna)->keyBy('key');
    $audit = AuditLog::query()->where('action', 'outcome_insight_accepted')->sole();

    expect($insight->fresh()->status)->toBe(OutcomeInsightStatus::Accepted)
        ->and($insight->fresh()->applied_ref)->toStartWith('role_dna_version:')
        ->and($dna['skill:laravel']['level'])->toBe(RequirementLevel::Preferred->value)
        ->and($dna['skill:laravel']['origin'])->toBe(RoleDnaOrigin::HumanConfirmed->value)
        ->and($audit->changes)->toMatchArray(['subject' => 'skill:laravel', 'sample_size' => 4, 'reason' => 'Matches the team stack', 'level' => 'preferred'])
        ->and(fn () => $this->learning->reject($insight, $this->reviewer, 'Changed my mind'))->toThrow(DomainException::class, 'already');

    $rebuilt = collect(app(RoleDnaService::class)->rebuild($requisition, $this->reviewer)->dna)->keyBy('key');

    expect($rebuilt['outcome:skill:laravel']['level'])->toBe(RequirementLevel::Informational->value)
        ->and($rebuilt['outcome:skill:laravel']['origin'])->toBe(RoleDnaOrigin::Historical->value);
});

test('applying a suggestion to a requisition outside the reviewer\'s hierarchy is refused', function (): void {
    outcomeLearningSeedPattern($this->designation);
    $this->learning->refresh();
    $manager = Employee::factory()->create();
    $vpHr = User::factory()->create(['employee_id' => $manager->id])->assignRole('vp_hr');
    $elsewhere = RecruitmentRequisition::factory()->create(['designation_id' => $this->designation->id]);

    expect(fn () => $this->learning->accept(OutcomeInsight::query()->where('subject_key', 'skill:laravel')->sole(), $vpHr, $elsewhere))->toThrow(DomainException::class, 'requisition you can see');
});

test('rejecting needs a reason and deferring keeps the insight open; both are audited', function (): void {
    outcomeLearningSeedPattern($this->designation);
    $this->learning->refresh();
    $insight = OutcomeInsight::query()->where('subject_key', 'skill:laravel')->sole();

    expect(fn () => $this->learning->reject($insight, $this->reviewer, ' '))->toThrow(DomainException::class, 'reason');

    $this->learning->defer($insight, $this->reviewer);

    expect($insight->fresh()->status)->toBe(OutcomeInsightStatus::Deferred)
        ->and($insight->fresh()->status->isOpen())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'outcome_insight_deferred')->exists())->toBeTrue();
});

test('a source pattern is accepted into Hiring Memory as an aggregate with no person attached', function (): void {
    $source = CandidateSource::factory()->create(['name' => 'Referral']);

    foreach ([OutcomeType::Joined, OutcomeType::Joined, OutcomeType::NoShow] as $type) {
        $application = CandidateApplication::factory()->create(['candidate_id' => Candidate::factory()->create(['source_id' => $source->id, 'full_name' => 'PRIVATE-PERSON'])->id]);
        HiringOutcome::factory()->create(['outcome_type' => $type, 'category' => $type->category(), 'candidate_application_id' => $application->id]);
    }

    $this->learning->refresh();
    $insight = OutcomeInsight::query()->where('kind', OutcomeInsightKind::SourcePattern)->sole();
    $this->learning->accept($insight, $this->reviewer);
    $memory = HiringMemoryRecord::query()->where('memory_type', MemoryType::OutcomePattern)->sole();

    expect($insight->insight)->toContain('Of 3 final joining outcomes for candidates from Referral, 2 joined')
        ->and($memory->candidate_application_id)->toBeNull()
        ->and($memory->requisition_id)->toBeNull()
        ->and(json_encode($memory->facts))->not->toContain('PRIVATE-PERSON')
        ->and($insight->fresh()->applied_ref)->toBe("hiring_memory:{$memory->id}");
});

test('accepted outcome patterns are Talent Signal context only and never change the band', function (): void {
    $application = CandidateApplication::factory()->create();
    $application->requisition->update(['designation_id' => $this->designation->id, 'skills' => ['PHP', 'SQL', 'AWS'], 'experience_min' => 3, 'experience_max' => 6, 'qualification' => null, 'salary_min' => null, 'salary_max' => null, 'target_joining_date' => null]);
    $application->candidate->update(['skills' => ['PHP', 'Laravel'], 'total_experience' => 4, 'qualification' => null, 'current_city' => null, 'expected_salary' => null]);
    $before = app(TalentSignalService::class)->refresh($application->fresh());

    OutcomeInsight::factory()->create(['designation_id' => $this->designation->id, 'status' => OutcomeInsightStatus::Accepted, 'evidence' => ['skill_label' => 'Laravel', 'active_with_skill' => 3, 'observed_active' => 3, 'checkpoint_days' => 90]]);
    $after = app(TalentSignalService::class)->refresh($application->fresh(), force: true);

    expect($after->band)->toBe($before->band)
        ->and($after->required_coverage_pct)->toBe($before->required_coverage_pct)
        ->and($after->components['items']['outcome_patterns']['status'])->toBe('context')
        ->and($after->components['items']['outcome_patterns']['summary'])->toContain('Laravel (listed)');
});

test('outcome insights need outcomes.review, and only open insights can be decided', function (): void {
    $insight = OutcomeInsight::factory()->create();

    actingAs(User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('manager'));
    get(OutcomeInsightResource::getUrl())->assertForbidden();

    actingAs(User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('vp_hr'));
    get(OutcomeInsightResource::getUrl('view', ['record' => $insight]))->assertOk()->assertSee('Observational');

    Livewire::test(ViewOutcomeInsight::class, ['record' => $insight->getRouteKey()])
        ->callAction('reject', ['reason' => 'Sample too narrow'])
        ->assertHasNoActionErrors();

    Livewire::test(ViewOutcomeInsight::class, ['record' => $insight->getRouteKey()])
        ->assertActionHidden('accept')
        ->assertActionHidden('reject');
});
