<?php

use App\Enums\CandidateStage;
use App\Enums\CommunicationChannel;
use App\Enums\PreferenceStatus;
use App\Enums\RediscoveryResultStatus;
use App\Enums\SignalBand;
use App\Enums\TalentPoolVisibility;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateTimelineEvent;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\RediscoveryResult;
use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use App\Models\User;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Intelligence\TalentRediscoveryService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->recruiter = Employee::factory()->create();
    $this->user = User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
    $this->requisition = RecruitmentRequisition::factory()->create(['skills' => ['PHP', 'Laravel', 'SQL'], 'experience_min' => 2, 'experience_max' => 6, 'qualification' => null, 'location_id' => null, 'salary_min' => null, 'salary_max' => null]);
    $this->requisition->recruiters()->attach($this->recruiter->id);
    $this->service = app(TalentRediscoveryService::class);
});

/**
 * A candidate the recruiter can see (they own one of the candidate's other applications).
 */
function rediscoverableCandidate(Employee $recruiter, array $attributes): Candidate
{
    $candidate = Candidate::factory()->create(['total_experience' => 4, ...$attributes]);
    CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'recruiter_id' => $recruiter->id, 'current_stage' => CandidateStage::Interview1]);

    return $candidate;
}

test('rediscovers visible candidates who align, strongest first, with why', function (): void {
    $strong = rediscoverableCandidate($this->recruiter, ['skills' => ['PHP', 'Laravel', 'SQL']]);
    $partial = rediscoverableCandidate($this->recruiter, ['skills' => ['PHP', 'Laravel']]);
    rediscoverableCandidate($this->recruiter, ['skills' => ['Java']]);

    $run = $this->service->run($this->requisition, $this->user);
    $results = $run->results;

    expect($run->candidates_scanned)->toBe(3)
        ->and($results->pluck('candidate_id')->all())->toBe([$strong->id, $partial->id])
        ->and($results->first()->band)->toBe(SignalBand::Strong)
        ->and($results->first()->summary['reasons'][0])->toContain('3 of 3 required skills')
        ->and(collect($results->first()->summary['reasons'])->implode(' '))->toContain('Previous applications')
        ->and($results->first()->evidence()->count())->toBeGreaterThan(0)
        ->and($run->role_dna_version_id)->not->toBeNull();
});

test('candidates outside the user\'s hierarchy are never rediscovered, but shared talent-pool members are', function (): void {
    $hidden = Candidate::factory()->create(['skills' => ['PHP', 'Laravel', 'SQL'], 'total_experience' => 4]);
    CandidateApplication::factory()->create(['candidate_id' => $hidden->id]);

    $pooled = Candidate::factory()->create(['skills' => ['PHP', 'Laravel', 'SQL'], 'total_experience' => 4]);
    $pool = TalentPool::factory()->create(['visibility' => TalentPoolVisibility::Organization]);
    TalentPoolMembership::factory()->create(['talent_pool_id' => $pool->id, 'candidate_id' => $pooled->id]);

    $ids = $this->service->run($this->requisition, $this->user)->results->pluck('candidate_id');

    expect($ids)->toContain($pooled->id)->not->toContain($hidden->id);
});

test('people already on the requisition and people already hired are excluded', function (): void {
    $applied = rediscoverableCandidate($this->recruiter, ['skills' => ['PHP', 'Laravel', 'SQL']]);
    CandidateApplication::factory()->create(['candidate_id' => $applied->id, 'requisition_id' => $this->requisition->id, 'recruiter_id' => $this->recruiter->id]);
    $employee = rediscoverableCandidate($this->recruiter, ['skills' => ['PHP', 'Laravel', 'SQL']]);
    Employee::factory()->create(['candidate_id' => $employee->id]);
    $joined = Candidate::factory()->create(['skills' => ['PHP', 'Laravel', 'SQL'], 'total_experience' => 4]);
    CandidateApplication::factory()->create(['candidate_id' => $joined->id, 'recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::Joined]);

    expect($this->service->run($this->requisition, $this->user)->results)->toBeEmpty();
});

test('people who opted out of every channel are flagged, not hidden', function (): void {
    $candidate = rediscoverableCandidate($this->recruiter, ['skills' => ['PHP', 'Laravel', 'SQL']]);

    foreach (CommunicationChannel::sendable() as $channel) {
        app(CommunicationPreferenceService::class)->set($candidate, $channel, PreferenceStatus::OptedOut, 'test');
    }

    expect($this->service->run($this->requisition, $this->user)->results->sole()->do_not_contact)->toBeTrue();
});

test('adding to the requisition creates a Sourced application through the normal path; nothing else changes', function (): void {
    $candidate = rediscoverableCandidate($this->recruiter, ['skills' => ['PHP', 'Laravel', 'SQL']]);
    $before = $candidate->fresh()->updated_at;
    $result = $this->service->run($this->requisition, $this->user)->results->sole();

    $application = $this->service->addToRequisition($result, $this->user);

    expect($application->current_stage)->toBe(CandidateStage::Sourced)
        ->and($application->origin_channel)->toBe('rediscovery')
        ->and($application->recruiter_id)->toBe($this->recruiter->id)
        ->and($result->fresh()->status)->toBe(RediscoveryResultStatus::AddedToRequisition)
        ->and(CandidateTimelineEvent::query()->where('candidate_id', $candidate->id)->where('title', "Rediscovered for {$this->requisition->code}")->exists())->toBeTrue()
        ->and($candidate->fresh()->updated_at->equalTo($before))->toBeTrue()
        ->and(fn () => $this->service->addToRequisition($result->fresh(), $this->user))->toThrow(DomainException::class, 'already handled');
});

test('adding to a pool uses the talent pool service and dismissing needs a note', function (): void {
    rediscoverableCandidate($this->recruiter, ['skills' => ['PHP', 'Laravel', 'SQL']]);
    rediscoverableCandidate($this->recruiter, ['skills' => ['PHP', 'Laravel', 'SQL']]);
    [$first, $second] = $this->service->run($this->requisition, $this->user)->results->all();
    $pool = TalentPool::factory()->create(['visibility' => TalentPoolVisibility::Organization]);

    $this->service->addToPool($first, $pool, $this->user);

    expect(TalentPoolMembership::query()->where('talent_pool_id', $pool->id)->where('candidate_id', $first->candidate_id)->exists())->toBeTrue()
        ->and(fn () => $this->service->dismiss($second, $this->user, ''))->toThrow(DomainException::class);

    $this->service->dismiss($second, $this->user, 'Relocated abroad');

    expect(RediscoveryResult::query()->pluck('status')->all())->toBe([RediscoveryResultStatus::AddedToPool, RediscoveryResultStatus::Dismissed]);
});

test('the scan is bounded', function (): void {
    config(['intelligence.rediscovery.max_scan' => 2]);

    foreach (range(1, 4) as $i) {
        rediscoverableCandidate($this->recruiter, ['skills' => ['PHP', 'Laravel', 'SQL']]);
    }

    expect($this->service->run($this->requisition, $this->user)->candidates_scanned)->toBe(2);
});
