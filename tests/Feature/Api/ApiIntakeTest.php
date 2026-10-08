<?php

use App\Enums\ApiScope;
use App\Events\CandidateAppliedOnline;
use App\Models\ApiCredential;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\JobPosting;
use App\Models\RecruitmentRequisition;
use App\Models\Role;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\ApiWorld;

/*
 * SaaS-6: the API's one write — an applicant submitted to a live job posting — is the career
 * site's intake (duplicate hold, consent, events), runs once per Idempotency-Key, and is attributed
 * to the credential acting for its owner.
 */
beforeEach(function (): void {
    $this->world = ApiWorld::build($this->tenant);
    $this->posting = ApiWorld::livePosting($this->tenant);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiIntakeApplicant(array $overrides = []): array
{
    return [
        'full_name' => 'Meera Iyer',
        'email' => 'meera.iyer@example.com',
        'mobile' => '+91 98450 12345',
        'current_city' => 'Bengaluru',
        'total_experience' => 4,
        'consent_email' => true,
        'privacy_consent' => true,
        'source' => 'linkedin',
        ...$overrides,
    ];
}

function apiIntakePost(int $postingId, string $token, array $body, ?string $key = 'key-1'): TestResponse
{
    return test()->postJson('/api/v1/job-postings/'.$postingId.'/applications', $body, ApiWorld::headers($token, $key !== null ? ['Idempotency-Key' => $key] : []));
}

test('a submitted applicant becomes a candidate and an application on the posting, through the career intake', function (): void {
    Event::fake([CandidateAppliedOnline::class]);

    $response = apiIntakePost($this->posting->id, $this->world->tokenA, apiIntakeApplicant())->assertCreated();

    $application = CandidateApplication::query()->sole();
    expect($response->json('data.outcome'))->toBe('received')
        ->and($response->json('data.application.id'))->toBe($application->id)
        ->and($application)->origin_channel->toBe('api')->job_posting_id->toBe($this->posting->id)
        ->and($application->candidate)->full_name->toBe('Meera Iyer')->email->toBe('meera.iyer@example.com');
    Event::assertDispatched(CandidateAppliedOnline::class);

    $audit = AuditLog::query()->where('auditable_type', Candidate::class)->where('action', 'created')->sole();
    expect($audit)->actor_kind->toBe('api')->actor_type->toBe((new ApiCredential)->getMorphClass())->on_behalf_of_user_id->toBe($this->world->identity->adminA->id)->user_id->toBeNull();
});

test('the same Idempotency-Key replays the first response and creates nothing more; a different body is refused', function (): void {
    $first = apiIntakePost($this->posting->id, $this->world->tokenA, apiIntakeApplicant())->assertCreated();
    $again = apiIntakePost($this->posting->id, $this->world->tokenA, apiIntakeApplicant())->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json())->toBe($first->json())
        ->and(CandidateApplication::query()->count())->toBe(1);

    apiIntakePost($this->posting->id, $this->world->tokenA, apiIntakeApplicant(['full_name' => 'Someone Else']))->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
    apiIntakePost($this->posting->id, $this->world->tokenA, apiIntakeApplicant(), null)->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');

    // Keys belong to their credential: another credential's identical key is a different request.
    $other = ApiWorld::issue($this->tenant, $this->world->identity->adminA, 'Second');
    apiIntakePost($this->posting->id, $other, apiIntakeApplicant(['email' => 'second@example.com', 'mobile' => '+91 90000 00002']))->assertCreated();
    expect(CandidateApplication::query()->count())->toBe(2);
});

test('contact details matching an existing candidate are held — nothing is written to that candidate', function (): void {
    $existing = Candidate::factory()->create(['email' => 'meera.iyer@example.com', 'mobile' => '+919845012345']);

    apiIntakePost($this->posting->id, $this->world->tokenA, apiIntakeApplicant())->assertStatus(202)->assertJsonPath('data.outcome', 'held')->assertJsonPath('data.application', null);

    expect(CandidateApplication::query()->count())->toBe(0)
        ->and($existing->fresh()->applications()->count())->toBe(0);
});

test('validation, closed postings, other tenants\' postings and missing permission are refused', function (): void {
    apiIntakePost($this->posting->id, $this->world->tokenA, apiIntakeApplicant(['email' => 'not-an-email', 'privacy_consent' => false]), 'v')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['email', 'privacy_consent']]]);

    JobPosting::query()->whereKey($this->posting->id)->update(['status' => 'closed']);
    apiIntakePost($this->posting->id, $this->world->tokenA, apiIntakeApplicant(), 'c')->assertStatus(422)->assertJsonPath('error.code', 'posting_closed');

    $theirs = ApiWorld::livePosting($this->world->identity->beta);
    apiIntakePost($theirs->id, $this->world->tokenA, apiIntakeApplicant(), 't')->assertNotFound();

    $readOnly = ApiWorld::issue($this->tenant, $this->world->identity->adminA, 'Read only', [ApiScope::CandidatesRead]);
    apiIntakePost($theirs->id, $readOnly, apiIntakeApplicant(), 's')->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');

    expect(TenantContext::current()->run($this->world->identity->beta, fn () => CandidateApplication::query()->count()))->toBe(0)
        ->and(CandidateApplication::query()->count())->toBe(0);
});

test('the owner must still be allowed to add candidates and to see the posting\'s requisition', function (): void {
    $manager = $this->world->identity->personA;
    $manager->givePermissionTo('integrations.manage');
    $token = ApiWorld::issue($this->tenant, $manager->fresh(), 'Manager intake', [ApiScope::ApplicationsWrite]);

    apiIntakePost($this->posting->id, $token, apiIntakeApplicant(), 'outside')->assertNotFound()->assertJsonPath('error.code', 'not_found');

    RecruitmentRequisition::query()->whereKey($this->posting->requisition_id)->update(['manager_id' => $this->world->identity->personAInAcme->id]);
    apiIntakePost($this->posting->id, $token, apiIntakeApplicant(), 'inside')->assertCreated();

    Role::byKeyOrFail('manager')->revokePermissionTo('candidates.create');
    apiIntakePost($this->posting->id, $token, apiIntakeApplicant(['email' => 'second.applicant@example.com', 'mobile' => '+91 90000 11111']), 'revoked')->assertForbidden()->assertJsonPath('error.code', 'forbidden');

    expect(CandidateApplication::query()->count())->toBe(1);
});

test('a submission cannot set anything beyond the applicant\'s own details', function (): void {
    apiIntakePost($this->posting->id, $this->world->tokenA, apiIntakeApplicant([
        'tenant_id' => $this->world->identity->beta->id, 'candidate_code' => 'CAND-HACK', 'remarks' => 'VIP', 'current_salary' => 1, 'status' => 'hired', 'current_stage' => 'joined', 'recruiter_id' => 1,
    ]))->assertCreated();

    $application = CandidateApplication::query()->sole();
    expect($application)->tenant_id->toBe($this->tenant->id)->current_stage->value->toBe('sourced')->status->value->toBe('active')
        ->and($application->candidate)->candidate_code->not->toBe('CAND-HACK')->remarks->toBeNull()->current_salary->toBeNull();
});

test('the body must be JSON, well-formed and small', function (): void {
    $headers = ApiWorld::headers($this->world->tokenA, ['Idempotency-Key' => 'raw']);

    $this->call('POST', '/api/v1/job-postings/'.$this->posting->id.'/applications', [], [], [], $this->transformHeadersToServerVars([...$headers, 'Content-Type' => 'text/plain']), 'full_name=x')->assertStatus(415)->assertJsonPath('error.code', 'unsupported_media_type');
    $this->call('POST', '/api/v1/job-postings/'.$this->posting->id.'/applications', [], [], [], $this->transformHeadersToServerVars([...$headers, 'Content-Type' => 'application/json']), '{"full_name": ')->assertStatus(400)->assertJsonPath('error.code', 'malformed_json');
    $this->call('POST', '/api/v1/job-postings/'.$this->posting->id.'/applications', [], [], [], $this->transformHeadersToServerVars([...$headers, 'Content-Type' => 'application/json']), json_encode(['remarks' => str_repeat('a', 70000)]))->assertStatus(413)->assertJsonPath('error.code', 'payload_too_large');

    expect(CandidateApplication::query()->count())->toBe(0);
});
