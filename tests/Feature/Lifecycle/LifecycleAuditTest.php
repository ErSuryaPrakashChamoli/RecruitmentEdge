<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferRevisionStatus;
use App\Enums\OfferStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Services\Lifecycle\LifecycleAuditor;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, string> check => level
 */
function lifecycleAuditChecks(array $findings): array
{
    return collect($findings)->mapWithKeys(fn (array $finding) => [$finding['check'] => $finding['level']])->all();
}

test('legacy states are warnings, never called corruption', function (): void {
    $closed = CandidateApplication::factory()->create(['status' => ApplicationStatus::Rejected, 'current_stage' => CandidateStage::Selected]);
    Interview::factory()->create(['candidate_application_id' => $closed->id, 'status' => InterviewStatus::Scheduled]);
    Offer::factory()->create(['candidate_application_id' => $closed->id, 'status' => OfferStatus::Released]);
    Offer::factory()->create(['candidate_application_id' => $closed->id, 'status' => OfferStatus::Draft]);
    CandidateApplication::factory()->create(['current_stage' => CandidateStage::Joined]);

    $checks = lifecycleAuditChecks(app(LifecycleAuditor::class)->run());

    expect($checks)->toMatchArray([
        'open_interview_on_closed_application' => 'WARNING',
        'open_offer_on_closed_application' => 'WARNING',
        'multiple_open_offers' => 'WARNING',
        'offer_before_selection' => 'WARNING',
        'joined_stage_without_joining' => 'WARNING',
    ]);
    $this->artisan('lifecycle:audit')->expectsOutputToContain('may predate Phase 8.3')->assertSuccessful();
});

test('facts inconsistent under any schema version are errors and fail the command', function (): void {
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Expected]);
    Employee::factory()->create(['candidate_id' => $joining->candidateApplication->candidate_id]);
    $offer = Offer::factory()->create(['status' => OfferStatus::Released, 'offered_ctc' => 900000]);
    OfferRevision::factory()->create(['offer_id' => $offer->id, 'revision' => 1, 'status' => OfferRevisionStatus::Released, 'offered_ctc' => 800000]);

    $checks = lifecycleAuditChecks(app(LifecycleAuditor::class)->run());

    expect($checks)->toMatchArray(['employee_without_joined_joining' => 'ERROR', 'released_offer_changed' => 'ERROR']);
    $this->artisan('lifecycle:audit')->expectsOutputToContain('released_offer_changed')->assertFailed();
});

test('the audit is read-only: it writes nothing, whatever it finds', function (): void {
    $closed = CandidateApplication::factory()->create(['status' => ApplicationStatus::Dropout]);
    CandidateJoining::factory()->create(['candidate_application_id' => $closed->id, 'status' => JoiningStatus::Confirmed]);
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('lifecycle:audit', ['--limit' => 5])->assertSuccessful();

    expect($writes)->toBe([])
        ->and(CandidateJoining::query()->sole()->status)->toBe(JoiningStatus::Confirmed);
});

test('the application filter and per-check limit bound the report', function (): void {
    $applications = CandidateApplication::factory()->count(3)->create(['current_stage' => CandidateStage::Joined]);

    $one = collect(app(LifecycleAuditor::class)->run(applicationId: $applications[0]->id))->where('check', 'joined_stage_without_joining');
    $limited = collect(app(LifecycleAuditor::class)->run(limit: 2))->where('check', 'joined_stage_without_joining');

    expect($one->pluck('subject')->all())->toBe([$applications[0]->application_code])
        ->and($limited)->toHaveCount(2);
});
