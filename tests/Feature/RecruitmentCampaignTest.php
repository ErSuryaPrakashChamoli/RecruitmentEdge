<?php

use App\Enums\CampaignStatus;
use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Filament\Resources\RecruitmentCampaigns\Pages\CreateRecruitmentCampaign;
use App\Filament\Resources\RecruitmentCampaigns\Pages\ListRecruitmentCampaigns;
use App\Filament\Resources\RecruitmentCampaigns\RecruitmentCampaignResource;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Distribution\RecruitmentCampaignService;
use App\Services\RecruitmentAnalyticsService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

test('a campaign is created with a unique tracking code and linked requisitions and sources, audited', function (): void {
    $requisition = RecruitmentRequisition::factory()->create();
    $source = CandidateSource::factory()->create();

    $campaign = app(RecruitmentCampaignService::class)->save(null, ['name' => 'October Sales Hiring', 'status' => CampaignStatus::Active->value, 'budget' => 50000, 'target_hires' => 3], [$requisition->id], [$source->id]);
    $second = app(RecruitmentCampaignService::class)->save(null, ['name' => 'October Sales Hiring'], [], []);

    expect($campaign->code)->toBe('OCTOBERSALESHIRING')
        ->and($second->code)->toBe('OCTOBERSALESHIRING2')
        ->and($campaign->requisitions()->pluck('recruitment_requisitions.id')->all())->toBe([$requisition->id])
        ->and($campaign->sources()->pluck('candidate_sources.id')->all())->toBe([$source->id])
        ->and(AuditLog::query()->where('action', 'campaign_links_updated')->where('auditable_id', $campaign->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'created')->where('auditable_type', RecruitmentCampaign::class)->exists())->toBeTrue();
});

test('a campaign cannot end before it starts or have a negative budget', function (array $data, string $message): void {
    expect(fn () => app(RecruitmentCampaignService::class)->save(null, ['name' => 'X', ...$data], [], []))->toThrow(DomainException::class, $message);
})->with([
    'end before start' => [['starts_on' => '2026-10-10', 'ends_on' => '2026-10-01'], 'end before it starts'],
    'negative budget' => [['budget' => -5], 'negative'],
]);

test('only a running campaign resolves from a tracking code', function (): void {
    RecruitmentCampaign::factory()->create(['code' => 'LIVE']);
    RecruitmentCampaign::factory()->create(['code' => 'OVER', 'ends_on' => now()->subDay()]);
    RecruitmentCampaign::factory()->create(['code' => 'DRAFT', 'status' => CampaignStatus::Draft]);
    $service = app(RecruitmentCampaignService::class);

    expect($service->resolveTrackingCode('live')?->code)->toBe('LIVE')
        ->and($service->resolveTrackingCode('OVER'))->toBeNull()
        ->and($service->resolveTrackingCode('DRAFT'))->toBeNull();
});

test('campaign analytics count attributed applications, outcomes, spend and cost per hire', function (): void {
    $campaign = RecruitmentCampaign::factory()->create(['budget' => 100000, 'target_hires' => 4]);
    $hired = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Sourced]);
    $screened = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Sourced]);
    CandidateApplication::factory()->create();
    foreach ([$hired, $screened] as $application) {
        $application->forceFill(['campaign_id' => $campaign->id, 'origin_channel' => 'career_site'])->save();
    }
    app(StageTransitionService::class)->transitionTo($screened, CandidateStage::Screened);
    app(StageTransitionService::class)->transitionTo($hired, CandidateStage::Joined);
    CandidateJoining::factory()->create(['candidate_application_id' => $hired->id, 'status' => JoiningStatus::Joined]);
    RecruitmentCost::factory()->create(['campaign_id' => $campaign->id, 'amount' => 30000]);
    RecruitmentCost::factory()->create(['amount' => 999999]);

    $m = app(RecruitmentAnalyticsService::class)->campaignAnalytics($campaign);

    expect($m)->toMatchArray([
        'applications' => 2,
        'screened' => 2,
        'joined' => 1,
        'spend' => 30000.0,
        'budget_used_percent' => 30.0,
        'target_progress_percent' => 25.0,
        'cost_per_hire' => 30000.0,
        'conversion_percent' => 50.0,
        'by_origin' => ['career_site' => 2],
    ]);
});

describe('campaign UI and permissions', function (): void {
    beforeEach(function (): void {
        $this->seed(RolePermissionSeeder::class);
    });

    function campaignUser(string $role): User
    {
        $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $user->assignRole($role);
        actingAs($user);

        return $user;
    }

    test('recruiters cannot manage campaigns', function (): void {
        campaignUser('recruiter');

        $this->get(RecruitmentCampaignResource::getUrl('index'))->assertForbidden();
    });

    test('a manager only sees campaigns owned within their hierarchy', function (): void {
        $manager = campaignUser('manager');
        $mine = RecruitmentCampaign::factory()->create(['owner_id' => $manager->employee_id]);
        $theirs = RecruitmentCampaign::factory()->create(['owner_id' => Employee::factory()->create()->id]);

        Livewire::test(ListRecruitmentCampaigns::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);
        $this->get(RecruitmentCampaignResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
    });

    test('a manager creates a campaign from the form', function (): void {
        $manager = campaignUser('manager');

        Livewire::test(CreateRecruitmentCampaign::class)
            ->fillForm(['name' => 'Q4 Technology Hiring', 'status' => 'active', 'owner_id' => $manager->employee_id, 'budget' => 250000, 'target_hires' => 10])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(RecruitmentCampaign::query()->sole()->code)->toBe('Q4TECHNOLOGYHIRING');
    });
});
