<?php

use App\Enums\ApiScope;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Department;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Services\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\ApiWorld;

/*
 * SaaS-6: API resources are explicit representations — allow-listed fields, allow-listed query
 * parameters, cursor pagination — over queries already limited to the tenant and to what the
 * credential's owner may see.
 */
beforeEach(function (): void {
    $this->world = ApiWorld::build($this->tenant);
});

function apiResourcesGet(string $uri, string $token): TestResponse
{
    return test()->getJson($uri, ApiWorld::headers($token));
}

test('a candidate is represented by its allow-listed fields only — never salaries, remarks, file paths or internals', function (): void {
    $candidate = Candidate::factory()->create(['full_name' => 'Asha Rao', 'current_salary' => 900000, 'expected_salary' => 1200000, 'remarks' => 'Internal note', 'resume_path' => 'tenants/1/x.pdf', 'source_details' => 'Referral from VP']);

    $data = apiResourcesGet('/api/v1/candidates/'.$candidate->id, $this->world->tokenA)->assertOk()->json('data');

    expect(array_keys($data))->toBe(['id', 'code', 'full_name', 'email', 'mobile', 'current_city', 'location', 'qualification', 'total_experience', 'current_company', 'current_designation', 'notice_period_days', 'skills', 'source', 'created_at', 'updated_at'])
        ->and($data['full_name'])->toBe('Asha Rao')
        ->and(json_encode($data))->not->toContain('900000')->not->toContain('Internal note')->not->toContain('x.pdf')->not->toContain('Referral from VP');
});

test('requisitions, postings and applications expose no salary, remark, manager or rejection detail', function (): void {
    $posting = ApiWorld::livePosting($this->tenant);
    RecruitmentRequisition::query()->whereKey($posting->requisition_id)->update(['salary_min' => 500000, 'salary_max' => 700000, 'remarks' => 'Budget is tight']);
    $application = CandidateApplication::factory()->create(['remarks' => 'Weak communication']);

    $requisition = apiResourcesGet('/api/v1/requisitions/'.$posting->requisition_id, $this->world->tokenA)->assertOk()->json('data');
    $shownPosting = apiResourcesGet('/api/v1/job-postings/'.$posting->id, $this->world->tokenA)->assertOk()->json('data');
    $shownApplication = apiResourcesGet('/api/v1/applications/'.$application->id, $this->world->tokenA)->assertOk()->json('data');

    expect(json_encode($requisition))->not->toContain('500000')->not->toContain('Budget is tight')->not->toContain('manager')
        ->and($shownPosting)->toMatchArray(['id' => $posting->id, 'is_live' => true, 'status' => 'published'])
        ->and(json_encode($shownApplication))->not->toContain('Weak communication')
        ->and(array_keys($shownApplication))->toBe(['id', 'code', 'candidate_id', 'requisition_id', 'job_posting_id', 'stage', 'status', 'origin_channel', 'application_date', 'created_at', 'updated_at']);
});

test('lists are cursor-paginated by id with an allow-list of parameters; anything else is refused', function (): void {
    Candidate::factory()->count(5)->create();

    $first = apiResourcesGet('/api/v1/candidates?per_page=2', $this->world->tokenA)->assertOk();
    $second = apiResourcesGet('/api/v1/candidates?per_page=2&cursor='.$first->json('meta.next_cursor'), $this->world->tokenA)->assertOk();

    expect($first->json('data'))->toHaveCount(2)
        ->and(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')))->toBe([])
        ->and($first->json('meta.next_cursor'))->not->toBeNull();

    apiResourcesGet('/api/v1/candidates?tenant_id=2', $this->world->tokenA)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.details.tenant_id.0', 'Unknown query parameter "tenant_id".');
    apiResourcesGet('/api/v1/candidates?per_page=1000', $this->world->tokenA)->assertStatus(422);
    apiResourcesGet('/api/v1/candidates?sort=full_name', $this->world->tokenA)->assertStatus(422);
    apiResourcesGet('/api/v1/candidates?sort=-id&per_page=1', $this->world->tokenA)->assertJsonPath('data.0.id', Candidate::query()->max('id'));
});

test('filters are allow-listed: by email, update time, requisition, stage', function (): void {
    $match = Candidate::factory()->create(['email' => 'Asha.Rao@Example.com']);
    Candidate::factory()->count(2)->create();
    $application = CandidateApplication::factory()->create(['candidate_id' => $match->id]);

    expect(apiResourcesGet('/api/v1/candidates?email=asha.rao@example.com', $this->world->tokenA)->json('data.*.id'))->toBe([$match->id])
        ->and(apiResourcesGet('/api/v1/applications?candidate_id='.$match->id, $this->world->tokenA)->json('data.*.id'))->toBe([$application->id])
        ->and(apiResourcesGet('/api/v1/applications?requisition_id='.$application->requisition_id.'&stage='.$application->current_stage->value, $this->world->tokenA)->json('data.*.id'))->toBe([$application->id])
        ->and(apiResourcesGet('/api/v1/candidates?updated_since='.urlencode(now()->addDay()->toIso8601String()), $this->world->tokenA)->json('data'))->toBe([]);

    apiResourcesGet('/api/v1/applications?stage=hired_by_api', $this->world->tokenA)->assertStatus(422);
});

test('the credential sees what its owner sees: a narrower owner gets a narrower API', function (): void {
    $manager = $this->world->identity->personA;
    $manager->givePermissionTo('integrations.manage');
    $token = ApiWorld::issue($this->tenant, $manager->fresh(), 'Manager sync', [ApiScope::CandidatesRead, ApiScope::ApplicationsRead]);

    $teamRecruiter = Employee::factory()->create(['reports_to_id' => $this->world->identity->personAInAcme->id]);
    $mine = CandidateApplication::factory()->create(['recruiter_id' => $teamRecruiter->id]);
    $notMine = CandidateApplication::factory()->create(['recruiter_id' => Employee::factory()->create()->id]);

    $ids = apiResourcesGet('/api/v1/applications', $token)->assertOk()->json('data.*.id');

    expect($ids)->toContain($mine->id)->not->toContain($notMine->id);
    apiResourcesGet('/api/v1/applications/'.$notMine->id, $token)->assertNotFound();
    apiResourcesGet('/api/v1/candidates/'.$notMine->candidate_id, $token)->assertNotFound();
    apiResourcesGet('/api/v1/candidates/'.$mine->candidate_id, $token)->assertOk();
    expect(apiResourcesGet('/api/v1/candidates?per_page=100', $token)->assertOk()->json('data.*.id'))->toContain($mine->candidate_id)->not->toContain($notMine->candidate_id);
});

test('master data lists the tenant\'s own records only', function (): void {
    Department::factory()->create(['name' => 'Ours']);
    TenantContext::current()->run($this->world->identity->beta, fn () => Department::factory()->create(['name' => 'Theirs']));

    $names = apiResourcesGet('/api/v1/departments?per_page=100', $this->world->tokenA)->assertOk()->json('data.*.name');

    expect($names)->toContain('Ours')->not->toContain('Theirs');
});

test('read routes refuse methods they do not offer, and unknown paths answer in the API\'s error contract', function (): void {
    $this->deleteJson('/api/v1/candidates/1', [], ApiWorld::headers($this->world->tokenA))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    apiResourcesGet('/api/v1/employees', $this->world->tokenA)->assertNotFound()->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    apiResourcesGet('/api/v1/candidates/abc', $this->world->tokenA)->assertNotFound();
});
