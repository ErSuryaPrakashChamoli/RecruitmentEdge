<?php

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Services\CandidateIdentityNormalizer;
use App\Services\Tenancy\TenantCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

require_once __DIR__.'/Sec88ContainmentHelpers.php';

/**
 * SEC-88-01: an anonymous career-site submission that knows an existing candidate's EMAIL must not
 * change anything about that candidate. Contact match is not proof of identity.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->posting = sec88Posting();
    $this->victim = sec88ExistingCandidate();
});

test('a submission using an existing candidate\'s email leaves that candidate untouched, twice over', function (): void {
    $before = sec88Snapshot($this->victim);

    sec88Apply($this->posting, ['email' => 'real.person@example.com'])->assertRedirect(route('careers.applied', $this->posting->public_slug));
    sec88Apply($this->posting, ['email' => 'real.person@example.com'])->assertRedirect(route('careers.applied', $this->posting->public_slug));

    expect(sec88Snapshot($this->victim))->toEqual($before)
        ->and(Candidate::query()->count())->toBe(1)
        ->and(CandidateApplication::query()->count())->toBe(0);
});

test('the held submission is audited with ids only and the recruiter is alerted once', function (): void {
    $victimAuditRows = AuditLog::query()->where('auditable_type', Candidate::class)->where('auditable_id', $this->victim->id)->count();

    sec88Apply($this->posting, ['email' => 'real.person@example.com']);
    sec88Apply($this->posting, ['email' => 'real.person@example.com']);

    $audit = AuditLog::query()->where('action', 'career_application_held')->get();
    $recruiter = $this->posting->requisition->manager->user;
    $stored = json_encode($audit->map->only(['changes', 'old_values'])->all());

    expect($audit)->toHaveCount(2)
        ->and($audit->first()->changes['matched_candidate_id'])->toBe($this->victim->id)
        ->and(str_contains($stored, 'real.person@example.com'))->toBeFalse('audit must not hold the submitted email')
        ->and(str_contains($stored, '9900000001'))->toBeFalse('audit must not hold the submitted mobile')
        ->and(AuditLog::query()->where('auditable_type', Candidate::class)->where('auditable_id', $this->victim->id)->count())->toBe($victimAuditRows)
        ->and($recruiter->notifications()->count())->toBe(1);
});

test('a genuinely new applicant still becomes a candidate with an application, their file and their consent', function (): void {
    sec88Apply($this->posting)->assertRedirect(route('careers.applied', $this->posting->public_slug));

    $newcomer = Candidate::query()->where('email', 'someone.else@example.net')->sole();

    expect($newcomer->applications()->sole()->job_posting_id)->toBe($this->posting->id)
        ->and($newcomer->documents()->count())->toBe(1)
        ->and(sec88Snapshot($this->victim)['applications'])->toBe([]);
});

test('a weak name-only resemblance still creates a new candidate for HR duplicate review, as before', function (): void {
    sec88Apply($this->posting, ['full_name' => 'Real Person']);

    expect(Candidate::query()->count())->toBe(2)
        ->and(CandidateApplication::query()->sole()->candidate->email)->toBe('someone.else@example.net');
});

test('a second submission of the same details while the first is still being saved creates nothing and answers neutrally (Phase 8.9, P89-DQ-011)', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $inFlight = Cache::lock(TenantCache::key('career-apply:').sha1(CandidateIdentityNormalizer::email('someone.else@example.net').'|'.CandidateIdentityNormalizer::mobile('9900000001')), 60);
    $inFlight->get();

    sec88Apply($this->posting)->assertRedirect(route('careers.applied', $this->posting->public_slug));

    expect(Candidate::query()->count())->toBe(1)
        ->and(CandidateApplication::query()->count())->toBe(0);

    $inFlight->release();
    sec88Apply($this->posting)->assertRedirect(route('careers.applied', $this->posting->public_slug));

    expect(Candidate::query()->where('email', 'someone.else@example.net')->count())->toBe(1);
});
