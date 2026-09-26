<?php

use App\Enums\CommunicationChannel;
use App\Enums\DistributionStatus;
use App\Enums\JobPostingStatus;
use App\Enums\PreferenceStatus;
use App\Enums\RequisitionStatus;
use App\Events\CandidateAppliedOnline;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\JobPosting;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Distribution\JobDistributionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    CandidateSource::factory()->create(['name' => 'Website']);
    CandidateSource::factory()->create(['name' => 'LinkedIn']);
    $this->distribution = app(JobDistributionService::class);
});

function openPosting(array $attributes = []): JobPosting
{
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'manager_id' => Employee::factory()->create()->id]);

    return app(JobDistributionService::class)->savePosting($requisition, ['title' => 'Senior Sales Executive', 'description' => str_repeat('Drive enterprise sales across the region. ', 3), ...$attributes]);
}

test('publishing an Open requisition\'s posting to the career site makes it live', function (): void {
    $posting = openPosting();

    $this->distribution->publish($posting, ['career_site', 'xml_feed']);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Published)
        ->and($posting->distributions()->pluck('status', 'channel')->map->value->all())->toBe(['career_site' => 'published', 'xml_feed' => 'published'])
        ->and($posting->distributions()->where('channel', 'career_site')->value('external_url'))->toBe(route('careers.show', $posting->public_slug))
        ->and(AuditLog::query()->where('action', 'job_published')->exists())->toBeTrue();
    $this->get(route('careers.index'))->assertOk()->assertSee('Senior Sales Executive');
});

test('an unapproved requisition can never be published', function (RequisitionStatus $status): void {
    $posting = openPosting();
    lifecycleFixture(fn () => $posting->requisition->forceFill(['status' => $status])->save());

    $this->distribution->publish($posting->fresh(), ['career_site']);
})->throws(DomainException::class, 'Only an approved, Open requisition')->with([
    'draft' => RequisitionStatus::Draft,
    'pending approval' => RequisitionStatus::PendingApproval,
    'closed' => RequisitionStatus::Closed,
]);

test('a posting with an invalid description is refused by the connector validation', function (): void {
    $posting = openPosting();
    $posting->forceFill(['description' => 'Too short'])->save();

    $this->distribution->publish($posting, ['career_site']);
})->throws(DomainException::class, 'at least 50 characters');

test('publishing again to the same channel never duplicates the publication', function (): void {
    $posting = openPosting();
    $this->distribution->publish($posting, ['career_site']);

    $queued = $this->distribution->publish($posting->fresh(), ['career_site']);

    expect($queued)->toBeEmpty()->and($posting->distributions()->count())->toBe(1);
});

test('a job board without API access is recorded as failed, never as published', function (): void {
    $posting = openPosting();

    $this->distribution->publish($posting, ['linkedin']);

    $row = $posting->distributions()->sole();

    expect($row->status)->toBe(DistributionStatus::Failed)
        ->and($row->last_error)->toContain('not configured')
        ->and(AuditLog::query()->where('action', 'job_distribution_failed')->exists())->toBeTrue();
});

test('unpublishing hides the posting and pausing then republishing restores it', function (): void {
    $posting = openPosting();
    $this->distribution->publish($posting, ['career_site']);

    $this->distribution->pause($posting->fresh());
    $this->get(route('careers.show', $posting->public_slug))->assertNotFound();

    $this->distribution->republish($posting->fresh());
    $this->get(route('careers.show', $posting->public_slug))->assertOk();

    $this->distribution->unpublish($posting->fresh(), reason: 'Filled');
    expect($posting->fresh()->status)->toBe(JobPostingStatus::Closed)
        ->and($posting->distributions()->sole()->status)->toBe(DistributionStatus::Unpublished);
    $this->get(route('careers.show', $posting->public_slug))->assertNotFound();
});

test('postings whose requisition closed are unpublished by the daily sync', function (): void {
    $posting = openPosting();
    $this->distribution->publish($posting, ['career_site']);
    lifecycleFixture(fn () => $posting->requisition->forceFill(['status' => RequisitionStatus::Closed])->save());

    $this->artisan('jobs:sync-distributions')->expectsOutputToContain('Closed 1 stale job posting(s)')->assertSuccessful();

    expect($posting->fresh()->status)->toBe(JobPostingStatus::Closed);
});

describe('career site applications', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');
        $this->posting = openPosting();
        $this->distribution->publish($this->posting, ['career_site']);
    });

    function applicationPayload(array $overrides = []): array
    {
        return [
            'full_name' => 'Neha Kapoor',
            'email' => 'neha@example.com',
            'mobile' => '9822233344',
            'current_city' => 'Pune',
            'resume' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf'),
            'privacy_consent' => '1',
            'consent_email' => '1',
            ...$overrides,
        ];
    }

    test('an online application creates the candidate and application in the existing pipeline', function (): void {
        Event::fake([CandidateAppliedOnline::class]);

        $this->post(route('careers.apply', $this->posting->public_slug), applicationPayload())->assertRedirect(route('careers.applied', $this->posting->public_slug));

        $application = CandidateApplication::query()->sole();

        expect($application->requisition_id)->toBe($this->posting->requisition_id)
            ->and($application->job_posting_id)->toBe($this->posting->id)
            ->and($application->origin_channel)->toBe('career_site')
            ->and($application->recruiter_id)->toBe($this->posting->requisition->manager_id)
            ->and($application->candidate->source->name)->toBe('Website')
            ->and($application->candidate->documents()->sole()->document_type->value)->toBe('resume')
            ->and(app(CommunicationPreferenceService::class)->statusFor($application->candidate, CommunicationChannel::Email))->toBe(PreferenceStatus::Allowed);
        Event::assertDispatched(CandidateAppliedOnline::class);
    });

    test('an applicant matching an existing candidate by email reuses that candidate record', function (): void {
        $existing = Candidate::factory()->create(['email' => 'neha@example.com', 'full_name' => 'Neha K']);

        $this->post(route('careers.apply', $this->posting->public_slug), applicationPayload());

        expect(Candidate::query()->count())->toBe(1)
            ->and(CandidateApplication::query()->sole()->candidate_id)->toBe($existing->id)
            ->and($existing->fresh()->full_name)->toBe('Neha K');
    });

    test('applying twice to the same position does not create a second application', function (): void {
        $this->post(route('careers.apply', $this->posting->public_slug), applicationPayload());
        $this->post(route('careers.apply', $this->posting->public_slug), applicationPayload(['resume' => UploadedFile::fake()->create('cv2.pdf', 50, 'application/pdf')]))
            ->assertSessionHas('careers_existing', true);

        expect(CandidateApplication::query()->count())->toBe(1);
    });

    test('campaign and source links attribute the application', function (): void {
        $campaign = RecruitmentCampaign::factory()->create(['code' => 'OCTSALES']);

        $this->get(route('careers.show', ['slug' => $this->posting->public_slug, 'campaign' => 'octsales', 'utm_source' => 'linkedin']))->assertOk();
        $this->post(route('careers.apply', $this->posting->public_slug), applicationPayload());

        $application = CandidateApplication::query()->sole();

        expect($application->campaign_id)->toBe($campaign->id)
            ->and($application->origin_channel)->toBe('career_site:linkedin')
            ->and($application->candidate->source->name)->toBe('LinkedIn');
    });

    test('invalid, bot and unsafe submissions are rejected', function (array $overrides, string $field): void {
        $this->post(route('careers.apply', $this->posting->public_slug), applicationPayload($overrides))->assertSessionHasErrors($field);

        expect(CandidateApplication::query()->count())->toBe(0);
    })->with([
        'missing privacy consent' => [['privacy_consent' => null], 'privacy_consent'],
        'honeypot filled' => [['website' => 'http://spam.example'], 'website'],
        'executable resume' => [['resume' => UploadedFile::fake()->create('cv.exe', 10, 'application/x-msdownload')], 'resume'],
        'bad email' => [['email' => 'not-an-email'], 'email'],
    ]);

    test('an unpublished posting accepts no applications', function (): void {
        $this->distribution->unpublish($this->posting->fresh(), reason: 'Closed');

        $this->post(route('careers.apply', $this->posting->public_slug), applicationPayload())->assertNotFound();
    });

    test('the public page never shows internal requisition remarks or a hidden salary', function (): void {
        $this->posting->requisition->update(['remarks' => 'INTERNAL: budget is tight', 'salary_min' => 500000, 'salary_max' => 900000]);

        $this->get(route('careers.show', $this->posting->public_slug))->assertOk()->assertDontSee('INTERNAL: budget is tight')->assertDontSee('900,000');
    });

    test('the XML feed lists live postings as valid XML', function (): void {
        $response = $this->get(route('careers.feed'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = simplexml_load_string($response->getContent());

        expect((string) $xml->job[0]->title)->toBe('Senior Sales Executive');
    });
});

test('job publishing is limited to users with jobs.publish and requisitions they can see', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $posting = openPosting();
    $recruiter = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $recruiter->assignRole('recruiter');
    $manager = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $manager->assignRole('manager');

    actingAs($recruiter);
    $this->get(JobPostingResource::getUrl('index'))->assertForbidden();

    actingAs($manager);
    $this->get(JobPostingResource::getUrl('view', ['record' => $posting]))->assertNotFound();
});

test('a manager publishes from the posting page', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $manager = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $manager->assignRole('manager');
    actingAs($manager);
    $posting = openPosting();
    $posting->requisition->update(['manager_id' => $manager->employee_id]);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('publish', ['channels' => ['career_site']])
        ->assertNotified('Publishing to 1 channel(s)');

    expect($posting->fresh()->status)->toBe(JobPostingStatus::Published);
});
