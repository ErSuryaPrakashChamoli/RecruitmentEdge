<?php

use App\Enums\TalentPoolVisibility;
use App\Models\AiConversation;
use App\Models\AiToolCall;
use App\Models\AiUsageLog;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\Interview;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRejectionReason;
use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use App\Models\Tenant;
use App\Services\AI\Actions\ActionExecutor;
use App\Services\AI\Actions\ApprovalAuthority;
use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\WebSearchProviderInterface;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Privacy\AiConversationVisibility;
use App\Services\AI\Rag\VectorSearch;
use App\Services\AI\Tools\ToolRegistry;
use App\Services\AiAssistantService;
use App\Services\Tenancy\TenantContext;
use Tests\Feature\Ai\Fakes\PrivacyRecordingLlmProvider;
use Tests\Feature\Ai\Fakes\RecordingEmbeddingProvider;
use Tests\Feature\Ai\Fakes\RecordingWebSearchProvider;
use Tests\Feature\Tenancy\TenantWorld;

/*
 * SaaS-1: the AI Copilot of Tenant A (ALPHA) — every one of the 49 tools, retrieval, conversations,
 * approvals and usage metering — never returns, changes or counts anything of Tenant B (BRAVO).
 * BRAVO's records carry their own codes (series 990001) and marker, so any leak is visible.
 */
beforeEach(function (): void {
    config(['ai.features.web_search_enabled' => true, 'ai.features.rag_enabled' => true, 'ai.features.actions_enabled' => true]);
    $this->embeddings = new RecordingEmbeddingProvider;
    app()->instance(EmbeddingProviderInterface::class, $this->embeddings);
    app()->instance(WebSearchProviderInterface::class, new RecordingWebSearchProvider);
    app()->instance(LLMProviderInterface::class, new PrivacyRecordingLlmProvider('none', []));

    $this->alpha = TenantWorld::build(Tenant::factory()->create(['slug' => 'alpha']), 'ALPHA');
    $this->bravo = TenantWorld::build(Tenant::factory()->create(['slug' => 'bravo']), 'BRAVO', '990001');

    $this->bravoExtras = TenantContext::current()->run($this->bravo->tenant, function (): array {
        $pool = TalentPool::factory()->create(['name' => 'BRAVO pool', 'visibility' => TalentPoolVisibility::Organization]);
        TalentPoolMembership::factory()->create(['talent_pool_id' => $pool->id, 'candidate_id' => $this->bravo->candidate->id]);

        return ['pool' => $pool->id, 'reason' => RecruitmentRejectionReason::factory()->create(['name' => 'BRAVO reason'])->id];
    });

    $this->actInTenant($this->alpha->tenant);
    $this->actingAs($this->alpha->chro);
});

/**
 * @return list<string>
 */
function crossTenantAiSentinels(): array
{
    return ['BRAVO', 'CAND-2026-990001', 'APP-2026-990001', 'REQ-2026-990001', 'OFR-2026-990001', 'EMP-990001', 'EMP-990002'];
}

/**
 * Every registered tool, pointed at Tenant B's records.
 *
 * @return array<string, Closure(): array<string, mixed>>
 */
function crossTenantAiToolCases(): array
{
    $b = fn () => test()->bravo;

    return [
        'search_candidates' => fn () => ['query' => 'BRAVO'],
        'get_candidate' => fn () => ['candidate_id' => $b()->candidate->id],
        'list_talent_pools' => fn () => ['talent_pool_id' => test()->bravoExtras['pool']],
        'compare_candidates' => fn () => ['candidate_ids' => [$b()->candidate->id, test()->alpha->candidate->id]],
        'summarize_candidate' => fn () => ['candidate_id' => $b()->candidate->id],
        'find_stuck_candidates' => fn () => ['days' => 0],
        'find_duplicate_candidates' => fn () => ['candidate_id' => test()->alpha->candidate->id],
        'get_candidate_timeline' => fn () => ['application_id' => $b()->application->id],
        'list_overdue_followups' => fn () => [],
        'recommend_next_step' => fn () => ['application_id' => $b()->application->id],
        'search_requisitions' => fn () => ['query' => 'REQ-2026'],
        'get_requisition' => fn () => ['requisition_id' => $b()->requisition->id],
        'get_requisition_pipeline' => fn () => ['requisition_id' => $b()->requisition->id],
        'find_at_risk_requisitions' => fn () => [],
        'generate_jd' => fn () => ['title' => 'Field Sales Executive', 'skills' => 'Sales'],
        'improve_jd' => fn () => ['job_description' => 'We need a field sales executive.'],
        'analyze_funnel' => fn () => [],
        'analyze_sources' => fn () => [],
        'time_to_hire' => fn () => [],
        'forecast_hiring' => fn () => ['target_hires' => 3],
        'generate_dashboard_insights' => fn () => [],
        'get_recruiter_performance' => fn () => ['employee_id' => $b()->recruiterEmployee->id],
        'compare_recruiters' => fn () => ['employee_ids' => [$b()->recruiterEmployee->id, $b()->chroEmployee->id]],
        'find_inactive_recruiters' => fn () => ['days' => 0],
        'generate_interview_questions' => fn () => ['role' => 'Field Sales', 'candidate_id' => $b()->candidate->id],
        'generate_interview_plan' => fn () => ['role' => 'Field Sales'],
        'search_interviews' => fn () => [],
        'summarize_interview_feedback' => fn () => ['application_id' => $b()->application->id],
        'analyze_offers' => fn () => [],
        'search_offers' => fn () => [],
        'analyze_joining_conversion' => fn () => [],
        'find_joining_risks' => fn () => [],
        'search_knowledge_base' => fn () => ['query' => 'leave policy'],
        'web_research' => fn () => ['topic' => 'Field sales salary benchmarks', 'location' => 'Pune'],
        'build_recruitment_plan' => fn () => ['target_hires' => 2, 'days' => 30, 'role' => 'Field Sales', 'location' => 'Pune'],
        'assign_candidates_to_recruiter' => fn () => ['application_ids' => [$b()->application->id], 'recruiter_employee_id' => test()->alpha->recruiterEmployee->id],
        'move_candidates_stage' => fn () => ['application_ids' => [$b()->application->id], 'stage' => 'shortlisted', 'remarks' => 'Cross-tenant attempt'],
        'reject_candidates' => fn () => ['application_ids' => [$b()->application->id], 'rejection_reason_id' => test()->bravoExtras['reason'], 'remarks' => 'Cross-tenant attempt'],
        'create_followup' => fn () => ['application_id' => $b()->application->id, 'followup_type' => 'call', 'followup_date' => now()->addDay()->toIso8601String(), 'remarks' => 'Cross-tenant attempt'],
        'schedule_interview' => fn () => ['application_id' => $b()->application->id, 'interviewer_employee_id' => $b()->recruiterEmployee->id, 'scheduled_at' => now()->addDays(2)->toIso8601String(), 'mode' => 'video_call'],
        'draft_candidate_email' => fn () => ['candidate_id' => $b()->candidate->id, 'purpose' => 'interview invitation'],
        'send_candidate_email' => fn () => ['candidate_id' => $b()->candidate->id, 'subject' => 'Next steps', 'body' => 'Hello, thank you.'],
        'get_role_dna' => fn () => ['requisition_id' => $b()->requisition->id],
        'explain_talent_signal' => fn () => ['application_id' => $b()->application->id],
        'get_hiring_health' => fn () => ['requisition_id' => $b()->requisition->id],
        'list_hiring_risks' => fn () => [],
        'rediscover_talent' => fn () => ['requisition_id' => $b()->requisition->id],
        'get_hiring_memory' => fn () => ['requisition_id' => $b()->requisition->id],
        'summarize_hiring_outcomes' => fn () => ['requisition_id' => $b()->requisition->id],
    ];
}

test('every registered AI tool has a cross-tenant case', function (): void {
    $registered = collect(app(ToolRegistry::class)->all())->map(fn ($tool) => $tool->name())->values()->all();

    expect($registered)->toHaveCount(49)
        ->and(array_keys(crossTenantAiToolCases()))->toEqualCanonicalizing($registered);
});

test('an AI tool in Tenant A never returns or changes Tenant B\'s data, even for a view-all user', function (string $tool): void {
    $bravoBefore = TenantContext::current()->run($this->bravo->tenant, fn (): array => [
        CandidateApplication::query()->find($this->bravo->application->id)->only(['current_stage', 'status', 'recruiter_id']),
        RecruitmentFollowup::query()->count(),
        Interview::query()->count(),
        CandidateCommunication::query()->count(),
    ]);

    $result = app(ToolRegistry::class)->find($tool)->handle(crossTenantAiToolCases()[$tool](), $this->alpha->chro);
    $raw = (string) json_encode($result->toArray());

    foreach (crossTenantAiSentinels() as $sentinel) {
        $at = strpos($raw, $sentinel);
        expect($at)->toBeFalse("{$tool} returned Tenant B's {$sentinel}: ".($at === false ? '' : substr($raw, max(0, $at - 80), 160)));
    }

    $bravoAfter = TenantContext::current()->run($this->bravo->tenant, fn (): array => [
        CandidateApplication::query()->find($this->bravo->application->id)->only(['current_stage', 'status', 'recruiter_id']),
        RecruitmentFollowup::query()->count(),
        Interview::query()->count(),
        CandidateCommunication::query()->count(),
    ]);

    expect($bravoAfter)->toBe($bravoBefore, "{$tool} changed Tenant B");
})->with(array_keys(crossTenantAiToolCases()));

test('retrieval searches only the tenant\'s own knowledge base — chunks are filtered by their own tenant', function (): void {
    $chunks = app(VectorSearch::class)->search('leave policy', 10);
    $fallback = app(AiAssistantService::class)->search('leave');

    expect($chunks)->not->toBeEmpty()
        ->and($chunks->pluck('content')->implode(' '))->toContain('ALPHA')->not->toContain('BRAVO')
        ->and(json_encode($fallback))->not->toContain('BRAVO');
});

test('conversation review, approvals and usage stay in the tenant', function (): void {
    $visible = app(AiConversationVisibility::class)->scope(AiConversation::query(), $this->alpha->chro)->pluck('id')->all();
    // Requested by the same person, so only the tenant boundary can refuse the approval below.
    $bravoCall = TenantContext::current()->run($this->bravo->tenant, fn () => AiToolCall::factory()->create(['requested_by' => $this->alpha->chro->id]));
    $authority = app(ApprovalAuthority::class);

    expect($visible)->toContain($this->alpha->conversation->id)->not->toContain($this->bravo->conversation->id)
        ->and(AiToolCall::query()->find($bravoCall->id))->toBeNull()
        ->and($authority->fingerprint($this->alpha->chro))->not->toBe(TenantContext::current()->run($this->bravo->tenant, fn () => $authority->fingerprint($this->alpha->chro)));

    expect(fn () => app(ActionExecutor::class)->approve($bravoCall, $this->alpha->chro))->toThrow(Exception::class)
        ->and(TenantContext::current()->run($this->bravo->tenant, fn () => $bravoCall->fresh()->status))->toBe($bravoCall->status);

    $usage = fn (): array => AiUsageLog::query()->withoutTenancy()->selectRaw('tenant_id, count(*) as calls')->groupBy('tenant_id')->pluck('calls', 'tenant_id')->map(fn ($calls) => (int) $calls)->all();
    $before = $usage();

    app(AiGateway::class)->embed(['ALPHA question'], context: 'query');
    TenantContext::current()->runWithoutTenant(fn () => app(AiGateway::class)->embed(['platform diagnostic'], context: 'query'));

    $after = $usage();

    expect(($after[$this->alpha->tenant->id] ?? 0) - ($before[$this->alpha->tenant->id] ?? 0))->toBe(1)
        ->and($after[$this->bravo->tenant->id] ?? 0)->toBe($before[$this->bravo->tenant->id] ?? 0)
        ->and(array_sum($after) - array_sum($before))->toBe(1);
});
