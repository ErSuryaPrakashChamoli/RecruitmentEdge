<?php

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\PreferenceStatus;
use App\Enums\RequisitionStatus;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Distribution\JobDistributionService;
use App\Services\RecruitmentAnalyticsService;
use Database\Seeders\RolePermissionSeeder;

function analyticsMessage(CommunicationStatus $status, array $attributes = []): CandidateCommunication
{
    $message = CandidateCommunication::factory()->create($attributes);
    $message->forceFill(['status' => $status, ...($status === CommunicationStatus::Delivered ? ['delivered_at' => now()] : [])])->save();

    return $message;
}

test('delivery metrics are "not reported" until a provider reports delivery', function (): void {
    analyticsMessage(CommunicationStatus::Sent);
    analyticsMessage(CommunicationStatus::Blocked);
    analyticsMessage(CommunicationStatus::Failed);

    $m = app(RecruitmentAnalyticsService::class)->communicationAnalytics(now()->subDay(), now()->addDay());

    expect($m)->toMatchArray(['total' => 3, 'sent' => 1, 'blocked' => 1, 'failed' => 1, 'delivered' => null, 'delivery_rate' => null, 'opened' => null]);
});

test('provider-reported deliveries produce a delivery rate', function (): void {
    analyticsMessage(CommunicationStatus::Sent);
    analyticsMessage(CommunicationStatus::Delivered);

    $m = app(RecruitmentAnalyticsService::class)->communicationAnalytics(now()->subDay(), now()->addDay());

    expect($m['delivered'])->toBe(1)->and($m['delivery_rate'])->toBe(50.0)->and($m['by_channel'])->toBe(['email' => 2]);
});

test('opt-outs and interview confirmations are counted', function (): void {
    app(CommunicationPreferenceService::class)->set(Candidate::factory()->create(), CommunicationChannel::Email, PreferenceStatus::OptedOut, 'recruiter');
    Interview::factory()->create(['status' => InterviewStatus::Confirmed]);

    $m = app(RecruitmentAnalyticsService::class)->communicationAnalytics(now()->subDay(), now()->addDay());

    expect($m['opted_out'])->toBe(1)->and($m['interview_confirmations'])->toBe(1);
});

test('communication metrics are limited to the user\'s hierarchy', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('recruiter');
    analyticsMessage(CommunicationStatus::Sent, ['candidate_id' => CandidateApplication::factory()->create(['recruiter_id' => $user->employee_id])->candidate_id]);
    analyticsMessage(CommunicationStatus::Sent);

    expect(app(RecruitmentAnalyticsService::class)->communicationAnalytics(now()->subDay(), now()->addDay(), $user)['total'])->toBe(1);
});

test('distribution metrics group online applications by channel with their outcomes', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
    $posting = app(JobDistributionService::class)->savePosting($requisition, ['title' => 'Analyst', 'description' => str_repeat('Analyse data and report findings. ', 3)]);
    app(JobDistributionService::class)->publish($posting, ['career_site']);
    $hired = CandidateApplication::factory()->create(['origin_channel' => 'career_site']);
    CandidateApplication::factory()->create(['origin_channel' => 'career_site:linkedin']);
    CandidateApplication::factory()->create();
    Interview::factory()->create(['candidate_application_id' => $hired->id, 'status' => InterviewStatus::Completed]);
    CandidateJoining::factory()->create(['candidate_application_id' => $hired->id, 'status' => JoiningStatus::Joined]);

    $m = app(RecruitmentAnalyticsService::class)->distributionAnalytics(now()->subDay(), now()->addDay());

    expect($m['live_postings'])->toBe(1)
        ->and($m['published_postings'])->toBe(1)
        ->and($m['channels']->firstWhere('channel', 'career_site'))->toMatchArray(['applications' => 1, 'interviewed' => 1, 'joined' => 1])
        ->and($m['channels']->firstWhere('channel', 'career_site:linkedin')['applications'])->toBe(1);
});
