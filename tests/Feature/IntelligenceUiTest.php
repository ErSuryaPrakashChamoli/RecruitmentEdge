<?php

use App\Enums\HiringRiskStatus;
use App\Enums\RequisitionStatus;
use App\Filament\Pages\IntelligenceOverview;
use App\Filament\Resources\CandidateApplications\Pages\ViewCandidateApplication;
use App\Filament\Resources\HiringMemoryRecords\HiringMemoryRecordResource;
use App\Filament\Resources\HiringMemoryRecords\Pages\ViewHiringMemoryRecord;
use App\Filament\Resources\HiringRisks\HiringRiskResource;
use App\Filament\Resources\HiringRisks\Pages\ListHiringRisks;
use App\Filament\Resources\RecruitmentRequisitions\Pages\RequisitionIntelligence;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\HiringHealthSnapshot;
use App\Models\HiringMemoryRecord;
use App\Models\HiringRisk;
use App\Models\RecruitmentRequisition;
use App\Models\RoleDnaProfile;
use App\Models\User;
use App\Services\AI\Tools\ToolRegistry;
use App\Services\Intelligence\EvidenceLookup;
use App\Services\Intelligence\HiringHealthService;
use App\Services\Intelligence\HiringMemoryService;
use App\Services\Intelligence\HiringRiskRadar;
use App\Services\Intelligence\RoleDnaService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
    $this->recruiter = Employee::factory()->reportingTo($this->manager)->create();
    $this->recruiterUser = User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');

    $this->mine = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'skills' => ['PHP'], 'opening_date' => now()->subDays(50)]);
    $this->mine->recruiters()->attach($this->recruiter->id);
    $this->theirs = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
});

test('intelligence pages render for the people allowed to see them', function (): void {
    actingAs($this->recruiterUser);

    get(IntelligenceOverview::getUrl())->assertOk()->assertSee($this->mine->code)->assertDontSee($this->theirs->code);
    get(RecruitmentRequisitionResource::getUrl('intelligence', ['record' => $this->mine]))->assertOk()->assertSee('Hiring Health');
    get(HiringRiskResource::getUrl('index'))->assertOk();
});

test('a recruiter cannot open another team\'s requisition intelligence, or Hiring Memory at all', function (): void {
    actingAs($this->recruiterUser);

    get(RecruitmentRequisitionResource::getUrl('intelligence', ['record' => $this->theirs]))->assertNotFound();
    get(HiringMemoryRecordResource::getUrl('index'))->assertForbidden();
});

test('evidence of a requisition the viewer cannot see is never returned, even with a tampered id', function (): void {
    $snapshot = app(HiringHealthService::class)->refresh($this->theirs);

    expect(app(EvidenceLookup::class)->for($this->recruiterUser, 'hiring_health', $snapshot->id))->toBeNull()
        ->and(app(EvidenceLookup::class)->for($this->recruiterUser, 'App\\Models\\User', 1))->toBeNull();

    actingAs($this->recruiterUser);
    Livewire::test(RequisitionIntelligence::class, ['record' => $this->mine->id])
        ->mountAction(TestAction::make('evidence')->arguments(['owner' => 'hiring_health', 'id' => $snapshot->id]))
        ->assertMountedActionModalSee('not available to you');
});

test('a manager builds, extends and confirms Role DNA from the page; a recruiter cannot', function (): void {
    actingAs($this->recruiterUser);
    app(RoleDnaService::class)->currentVersionFor($this->mine);
    Livewire::test(RequisitionIntelligence::class, ['record' => $this->mine->id])
        ->assertActionHidden('buildRoleDna')
        ->assertDontSee('Rebuild from requisition')
        ->assertDontSee('Add attribute')
        ->assertDontSee('Suggest with AI')
        ->assertSee('Rediscover talent');

    actingAs($this->managerUser);
    Livewire::test(RequisitionIntelligence::class, ['record' => $this->mine->id])
        ->callAction('buildRoleDna')
        ->callAction('addAttribute', ['category' => 'skill', 'label' => 'Redis', 'level' => 'preferred'])
        ->callAction('confirmRoleDna')
        ->assertSee('Redis');

    $profile = RoleDnaProfile::query()->where('requisition_id', $this->mine->id)->sole();

    expect($profile->current_version)->toBe(2)
        ->and($profile->status->value)->toBe('confirmed');
});

test('asking for AI suggestions without a provider says so and changes nothing', function (): void {
    actingAs($this->managerUser);
    app(RoleDnaService::class)->currentVersionFor($this->mine);

    Livewire::test(RequisitionIntelligence::class, ['record' => $this->mine->id])
        ->callAction('requestAiSuggestions')
        ->assertNotified('AI is not configured');

    expect(RoleDnaProfile::query()->sole()->current_version)->toBe(1);
});

test('health refresh, signals and rediscovery run from the page', function (): void {
    $candidate = Candidate::factory()->create(['skills' => ['PHP'], 'total_experience' => 3]);
    CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'recruiter_id' => $this->recruiter->id]);
    CandidateApplication::factory()->create(['requisition_id' => $this->mine->id, 'recruiter_id' => $this->recruiter->id]);
    actingAs($this->recruiterUser);

    Livewire::test(RequisitionIntelligence::class, ['record' => $this->mine->id])
        ->callAction('refreshHealth')->assertNotified('Hiring Health refreshed')
        ->callAction('refreshSignals')
        ->callAction('runRediscovery')
        ->assertSee($candidate->full_name)
        ->assertSee('Candidates — Talent Signal');

    expect(HiringHealthSnapshot::query()->where('requisition_id', $this->mine->id)->exists())->toBeTrue();
});

test('the risk register is scoped, and only risk managers can act on risks', function (): void {
    app(HiringRiskRadar::class)->scan();
    $mine = HiringRisk::query()->where('requisition_id', $this->mine->id)->first();
    $theirs = HiringRisk::query()->where('requisition_id', $this->theirs->id)->first();

    actingAs($this->recruiterUser);
    Livewire::test(ListHiringRisks::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertTableActionHidden('acknowledge', $mine);

    actingAs($this->managerUser);
    Livewire::test(ListHiringRisks::class)->callTableAction('acknowledge', $mine);

    expect($mine->fresh()->status)->toBe(HiringRiskStatus::Acknowledged);
});

test('Hiring Memory is viewable by managers and correctable only with memory.manage', function (): void {
    $record = app(HiringMemoryService::class)->captureRequisitionOutcome($this->mine, 'closed');

    actingAs($this->managerUser);
    Livewire::test(ViewHiringMemoryRecord::class, ['record' => $record->id])->assertOk()->assertSee('Recorded facts')->assertActionHidden('correct');

    actingAs(User::factory()->create()->assignRole('chro'));
    Livewire::test(ViewHiringMemoryRecord::class, ['record' => $record->id])
        ->callAction('correct', ['fact' => 'outcome', 'value' => 'cancelled', 'reason' => 'Was cancelled, not filled']);

    expect(HiringMemoryRecord::query()->where('is_current', true)->sole()->facts['outcome'])->toBe('cancelled');
});

test('the application page shows the candidate\'s Talent Signal with its evidence', function (): void {
    $application = CandidateApplication::factory()->create(['requisition_id' => $this->mine->id, 'recruiter_id' => $this->recruiter->id]);
    $application->candidate->update(['skills' => ['PHP'], 'total_experience' => 2]);
    actingAs($this->recruiterUser);

    Livewire::test(ViewCandidateApplication::class, ['record' => $application->id])
        ->mountAction('talentSignal')
        ->assertMountedActionModalSee('1 of 1 required skills')
        ->assertMountedActionModalSee('Has required skill: PHP');
});

test('Copilot intelligence tools are registered and respect scope and permissions', function (): void {
    $registry = app(ToolRegistry::class);

    expect(collect(['get_role_dna', 'explain_talent_signal', 'get_hiring_health', 'list_hiring_risks', 'rediscover_talent', 'get_hiring_memory'])->every(fn ($name) => $registry->find($name) !== null))->toBeTrue()
        ->and($registry->find('get_hiring_health')->handle(['requisition_id' => $this->theirs->id], $this->recruiterUser)->success)->toBeFalse()
        ->and($registry->find('get_hiring_health')->handle(['requisition_id' => $this->mine->id], $this->recruiterUser)->data['metrics'])->not->toBeEmpty()
        ->and($registry->find('get_hiring_memory')->permission())->toBe('intelligence.memory.view')
        ->and($this->recruiterUser->can('intelligence.memory.view'))->toBeFalse();
});

test('the Copilot rediscovery tool identifies people by candidate code, never by name or contact details', function (): void {
    $candidate = Candidate::factory()->create(['skills' => ['PHP'], 'total_experience' => 3, 'full_name' => 'Priya Secretname', 'email' => 'priya@private.example', 'mobile' => '9876500000']);
    CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'recruiter_id' => $this->recruiter->id]);

    $result = app(ToolRegistry::class)->find('rediscover_talent')->handle(['requisition_id' => $this->mine->id], $this->recruiterUser);
    $payload = json_encode($result->data).$result->summary;

    expect($result->data['suggestions'][0]['candidate'])->toBe($candidate->candidate_code)
        ->and($payload)->not->toContain('Priya')
        ->not->toContain('priya@private.example')
        ->not->toContain('9876500000');
});
