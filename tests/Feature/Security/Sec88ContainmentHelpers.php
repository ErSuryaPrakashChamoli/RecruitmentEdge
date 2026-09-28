<?php

use App\Enums\CommunicationChannel;
use App\Enums\PreferenceStatus;
use App\Enums\RequisitionStatus;
use App\Models\Candidate;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\JobPosting;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Distribution\JobDistributionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/*
 * Shared fixtures for the Phase 8.8 SEC-88-01 / SEC-88-09 containment suite. Not a test file.
 */

function sec88Posting(): JobPosting
{
    CandidateSource::factory()->create(['name' => 'Website', 'code' => CandidateSource::CODE_WEBSITE]);
    $recruiter = Employee::factory()->create();
    User::factory()->create(['employee_id' => $recruiter->id]);
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'manager_id' => $recruiter->id]);
    $distribution = app(JobDistributionService::class);
    $posting = $distribution->savePosting($requisition, ['title' => 'Field Sales Executive', 'description' => str_repeat('Drive enterprise sales across the region. ', 3)]);
    $distribution->publish($posting, ['career_site']);

    return $posting->fresh();
}

/**
 * An existing candidate who has opted out of email and sent STOP on WhatsApp.
 */
function sec88ExistingCandidate(): Candidate
{
    $candidate = Candidate::factory()->create(['full_name' => 'Real Person', 'email' => 'real.person@example.com', 'mobile' => '9811122233']);
    $preferences = app(CommunicationPreferenceService::class);
    $preferences->set($candidate, CommunicationChannel::Email, PreferenceStatus::OptedOut, 'candidate_portal');
    $preferences->set($candidate, CommunicationChannel::WhatsApp, PreferenceStatus::OptedOut, 'provider_opt_out', reason: 'STOP');

    return $candidate;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sec88Payload(array $overrides = []): array
{
    return [
        'full_name' => 'Anonymous Submitter',
        'email' => 'someone.else@example.net',
        'mobile' => '9900000001',
        'current_city' => 'Pune',
        'resume' => UploadedFile::fake()->create('cv.pdf', 60, 'application/pdf'),
        'privacy_consent' => '1',
        'consent_email' => '1',
        'consent_whatsapp' => '1',
        ...$overrides,
    ];
}

/**
 * The contact-detail combinations an attacker can know about a real candidate.
 *
 * @return array<string, array{0: array<string, string>}>
 */
function sec88MatchingVariants(): array
{
    return [
        'email only' => [['email' => 'real.person@example.com']],
        'mobile only' => [['mobile' => '9811122233']],
        'email + mobile' => [['email' => 'real.person@example.com', 'mobile' => '9811122233']],
        'name + email' => [['full_name' => 'Real Person', 'email' => 'real.person@example.com']],
        'name + mobile' => [['full_name' => 'Real Person', 'mobile' => '9811122233']],
    ];
}

function sec88Apply(JobPosting $posting, array $overrides = []): TestResponse
{
    return test()->post(route('careers.apply', $posting->public_slug), sec88Payload($overrides));
}

/**
 * Everything an anonymous submission could have changed on the candidate.
 *
 * @return array<string, mixed>
 */
function sec88Snapshot(Candidate $candidate): array
{
    $preferences = app(CommunicationPreferenceService::class);
    $fresh = $candidate->fresh();

    return [
        'record' => $fresh->only(['full_name', 'email', 'mobile', 'current_city', 'updated_at']),
        'email' => $preferences->statusFor($fresh, CommunicationChannel::Email),
        'whatsapp' => $preferences->statusFor($fresh, CommunicationChannel::WhatsApp),
        'applications' => $fresh->applications()->pluck('id')->all(),
        'documents' => $fresh->documents()->pluck('id')->all(),
        'timeline' => $fresh->timelineEvents()->pluck('id')->all(),
    ];
}
