<?php

use App\Enums\EvidenceType;
use App\Enums\MemoryType;
use App\Enums\RoleDnaOrigin;
use App\Enums\RoleDnaStatus;
use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\CandidateSource;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\HiringMemoryRecord;
use App\Models\IntelligenceEvidence;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Intelligence\RoleDnaService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('vp_hr');
    $this->service = app(RoleDnaService::class);
    $this->requisition = RecruitmentRequisition::factory()->create(['skills' => ['PHP', 'Laravel'], 'experience_min' => 2, 'experience_max' => 5, 'qualification' => 'B.Tech']);
});

function dnaKeys(RecruitmentRequisition $requisition): array
{
    return collect(app(RoleDnaService::class)->currentVersionFor($requisition)->dna)->pluck('origin', 'key')->all();
}

function dnaHireMemory(Designation $designation, array $skills, ?string $source = null, int $days = 20): HiringMemoryRecord
{
    return HiringMemoryRecord::query()->create([
        'memory_type' => MemoryType::Hire,
        'designation_id' => $designation->id,
        'facts' => ['skills' => $skills, 'source' => $source, 'days_to_hire' => $days],
        'summary' => 'Hired',
        'captured_at' => now(),
        'source_event' => 'test',
        'capture_key' => 'test:'.uniqid('', true),
    ]);
}

test('the first build takes configured requirements and inferred interview dimensions, excluding culture fit', function (): void {
    $keys = dnaKeys($this->requisition);

    expect($keys)->toMatchArray([
        'skill:php' => RoleDnaOrigin::Configured->value,
        'skill:laravel' => RoleDnaOrigin::Configured->value,
        'experience:range' => RoleDnaOrigin::Configured->value,
        'education:qualification' => RoleDnaOrigin::Configured->value,
        'interview:technical' => RoleDnaOrigin::Inferred->value,
    ])->and($keys)->not->toHaveKey('interview:culture_fit');
});

test('history is never invented: under three past hires the DNA says so', function (): void {
    dnaHireMemory($this->requisition->designation, ['PHP']);

    $history = collect(app(RoleDnaService::class)->currentVersionFor($this->requisition)->dna)->firstWhere('key', 'history:hires');

    expect($history['value'])->toBe('1 recorded')
        ->and($history['note'])->toContain('Insufficient history');
});

test('with enough past hires the DNA shows common skills, top source and median time to hire, each with evidence', function (): void {
    $source = CandidateSource::factory()->create(['name' => 'Naukri']);

    foreach ([10, 20, 30] as $days) {
        dnaHireMemory($this->requisition->designation, ['PHP', 'Docker'], $source->name, $days);
    }

    $version = $this->service->currentVersionFor($this->requisition);
    $dna = collect($version->dna)->keyBy('key');

    expect($dna['history:skill:docker']['value'])->toBe('3 of 3 hires')
        ->and($dna['sourcing:top_source']['value'])->toContain('Naukri')
        ->and($dna['history:time_to_hire']['value'])->toBe('20 days')
        ->and($version->evidence()->where('subject_key', 'history:skill:docker')->count())->toBe(3);
});

test('rebuilding an unchanged requisition keeps the version; a changed one creates a new version', function (): void {
    $first = $this->service->currentVersionFor($this->requisition);

    expect($this->service->rebuild($this->requisition->fresh())->id)->toBe($first->id);

    $this->requisition->update(['skills' => ['PHP', 'Laravel', 'Redis']]);
    $second = $this->service->rebuild($this->requisition->fresh(), $this->user);

    expect($second->version)->toBe(2)
        ->and(collect($second->dna)->pluck('key'))->toContain('skill:redis')
        ->and(collect($first->fresh()->dna)->pluck('key'))->not->toContain('skill:redis');
});

test('confirming an AI suggestion makes it human-confirmed, verifies its AI evidence and creates a version', function (): void {
    $profile = $this->service->profileFor($this->requisition);
    $this->service->currentVersionFor($this->requisition);
    $suggested = $this->service->applyAiSuggestions($profile->fresh(), [['category' => 'skill', 'label' => 'Docker', 'level' => 'preferred', 'reason' => 'Deployment']], 'test-model');

    expect($suggested->pendingSuggestions()->pluck('key')->all())->toBe(['skill:docker'])
        ->and($suggested->effectiveAttributes()->pluck('key'))->not->toContain('skill:docker');

    $confirmed = $this->service->confirmAttribute($this->requisition, 'skill:docker', $this->user);

    expect(collect($confirmed->dna)->firstWhere('key', 'skill:docker')['origin'])->toBe(RoleDnaOrigin::HumanConfirmed->value)
        ->and($confirmed->effectiveAttributes()->pluck('key'))->toContain('skill:docker')
        ->and($suggested->evidence()->where('subject_key', 'skill:docker')->sole()->verification_status)->toBe(VerificationStatus::Verified)
        ->and($confirmed->evidence()->where('subject_key', 'skill:docker')->pluck('evidence_type')->all())->toContain(EvidenceType::AiInference, EvidenceType::HumanConfirmation)
        ->and(AuditLog::query()->where('action', 'role_dna_attribute_confirmed')->exists())->toBeTrue();
});

test('rejecting an attribute keeps it for history but stops it being used', function (): void {
    $this->service->currentVersionFor($this->requisition);

    $version = $this->service->rejectAttribute($this->requisition, 'skill:laravel', $this->user, 'Not needed for this team');

    expect($version->effectiveAttributes()->pluck('key'))->not->toContain('skill:laravel')
        ->and(collect($version->dna)->firstWhere('key', 'skill:laravel')['note'])->toContain('Not needed')
        ->and(fn () => $this->service->rejectAttribute($this->requisition, 'skill:php', $this->user, ''))->toThrow(DomainException::class);
});

test('a person can add an attribute, and people-made decisions survive a rebuild', function (): void {
    $this->service->currentVersionFor($this->requisition);
    $this->service->addAttribute($this->requisition, ['category' => 'skill', 'label' => 'GraphQL', 'level' => 'required'], $this->user);
    $this->service->rejectAttribute($this->requisition, 'skill:php', $this->user, 'Legacy');

    $this->requisition->update(['qualification' => 'MCA']);
    $rebuilt = $this->service->rebuild($this->requisition->fresh(), $this->user);
    $dna = collect($rebuilt->dna)->keyBy('key');

    expect($dna['skill:graphql']['origin'])->toBe(RoleDnaOrigin::HumanConfirmed->value)
        ->and($dna['skill:php']['active'])->toBeFalse()
        ->and($dna['education:qualification']['value'])->toBe('MCA');
});

test('the Role DNA cannot be confirmed while AI suggestions are pending, and any change returns it to draft', function (): void {
    $profile = $this->service->profileFor($this->requisition);
    $this->service->currentVersionFor($this->requisition);
    $this->service->applyAiSuggestions($profile->fresh(), [['category' => 'behavioral', 'label' => 'Ownership', 'level' => 'informational', 'reason' => null]], 'm');

    expect(fn () => $this->service->confirmProfile($this->requisition, $this->user))->toThrow(DomainException::class, 'AI suggestion');

    $this->service->rejectAttribute($this->requisition, 'behavioral:ownership', $this->user, 'no');
    $this->service->confirmProfile($this->requisition, $this->user);
    expect($profile->fresh()->status)->toBe(RoleDnaStatus::Confirmed);

    $this->service->addAttribute($this->requisition, ['category' => 'skill', 'label' => 'Go', 'level' => 'preferred'], $this->user);
    expect($profile->fresh()->status)->toBe(RoleDnaStatus::Draft);
});

test('versions and evidence are immutable', function (): void {
    $version = $this->service->currentVersionFor($this->requisition);
    $evidence = $version->evidence()->first();

    expect(fn () => $version->update(['change_summary' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $evidence->update(['value' => 'tampered']))->toThrow(LogicException::class)
        ->and(IntelligenceEvidence::query()->where('verification_status', '!=', VerificationStatus::NotRequired)->count())->toBe(0);
});
