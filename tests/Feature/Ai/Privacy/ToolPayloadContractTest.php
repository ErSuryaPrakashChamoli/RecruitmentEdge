<?php

use App\Enums\AiMessageRole;
use App\Enums\AiToolCallStatus;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\HiringRiskType;
use App\Enums\InterviewStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Enums\TalentPoolVisibility;
use App\Enums\TimelineEventType;
use App\Models\AiActionLog;
use App\Models\AiConversation;
use App\Models\AiKnowledgeArticle;
use App\Models\AiToolCall;
use App\Models\AiToolResult;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\HiringOutcome;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\InterviewFeedback;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use App\Models\User;
use App\Services\AI\Actions\ActionExecutor;
use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\WebSearchProviderInterface;
use App\Services\AI\Orchestrator\AiOrchestrator;
use App\Services\AI\Tools\ToolRegistry;
use App\Services\CandidateTimelineService;
use App\Services\Intelligence\HiringMemoryService;
use App\Services\Intelligence\RoleDnaService;
use Database\Seeders\RolePermissionSeeder;
use Tests\Feature\Ai\Fakes\PrivacyRecordingLlmProvider;
use Tests\Feature\Ai\Fakes\RecordingEmbeddingProvider;
use Tests\Feature\Ai\Fakes\RecordingWebSearchProvider;

/**
 * Phase 8.1 contract: every registered AI tool, run through the real orchestrator (and approval
 * for write actions) against records seeded with unmistakable sentinel values. What would have
 * been sent to the provider — chat, the tools' own prompts, embeddings and web queries — and what
 * was persisted to AI tables must contain none of them.
 */
const TOOL_CONTRACT_SENTINELS = [
    'PRIVATE-CANDIDATE-ALICE', 'PRIVATE-CANDIDATE-ZED', 'PRIVATE-ALICE@example.invalid', '9999912345', '9999954321',
    '77777777', '99999999', '88888888', '55555555', 'PRIVATE-CANDIDATE-REMARK', 'PRIVATE-APP-REMARK', 'PRIVATE-REQ-REMARK',
    'PRIVATE-COMPANY-ACME', 'PRIVATE-EMPLOYEE-BOB', 'private-bob@example.invalid', '9888812345', 'PRIVATE-MANAGER-CAROL',
    'private-carol@example.invalid', '9777712345', 'PRIVATE-USER-DAVE', 'PRIVATE-INTERVIEW-REMARK', 'meet.example.invalid',
    'PRIVATE-OFFER-REMARK', 'PRIVATE-FOLLOWUP-REMARK', 'PRIVATE-NOTE-TEXT',
];

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config(['ai.features.web_search_enabled' => true, 'ai.features.rag_enabled' => true, 'ai.features.actions_enabled' => true]);
    $this->embeddings = new RecordingEmbeddingProvider;
    $this->search = new RecordingWebSearchProvider;
    app()->instance(EmbeddingProviderInterface::class, $this->embeddings);
    app()->instance(WebSearchProviderInterface::class, $this->search);

    $carol = Employee::factory()->create(['first_name' => 'PRIVATE-MANAGER-CAROL', 'last_name' => 'Sentinel', 'email' => 'private-carol@example.invalid', 'mobile' => '9777712345']);
    $bob = Employee::factory()->reportingTo($carol)->create(['first_name' => 'PRIVATE-EMPLOYEE-BOB', 'last_name' => 'Sentinel', 'email' => 'private-bob@example.invalid', 'mobile' => '9888812345']);
    Interviewer::query()->create(['employee_id' => $bob->id, 'is_active' => true]);
    $this->user = User::factory()->create(['name' => 'PRIVATE-USER-DAVE', 'employee_id' => $carol->id])->assignRole('chro');

    $requisition = RecruitmentRequisition::factory()->create([
        'status' => RequisitionStatus::Open, 'skills' => ['Laravel', 'PHP'], 'salary_min' => 55555555, 'salary_max' => 88888888,
        'remarks' => 'PRIVATE-REQ-REMARK', 'manager_id' => $carol->id, 'vp_hr_id' => $carol->id, 'opening_date' => now()->subDays(40),
    ]);
    $requisition->recruiters()->attach($bob->id);

    $alice = Candidate::factory()->create([
        'full_name' => 'PRIVATE-CANDIDATE-ALICE', 'email' => 'PRIVATE-ALICE@example.invalid', 'mobile' => '9999912345', 'alternate_mobile' => '9999954321',
        'current_salary' => 77777777, 'expected_salary' => 99999999, 'remarks' => 'PRIVATE-CANDIDATE-REMARK', 'current_company' => 'PRIVATE-COMPANY-ACME',
        'skills' => ['Laravel', 'PHP'], 'current_city' => 'Pune', 'total_experience' => 5,
    ]);
    $aliceApp = CandidateApplication::factory()->create([
        'candidate_id' => $alice->id, 'requisition_id' => $requisition->id, 'recruiter_id' => $bob->id, 'current_stage' => CandidateStage::Interview1,
        'status' => ApplicationStatus::Active, 'remarks' => 'PRIVATE-APP-REMARK', 'last_activity_at' => now()->subDays(10),
    ]);
    $zed = Candidate::factory()->create(['full_name' => 'PRIVATE-CANDIDATE-ZED', 'skills' => ['Laravel'], 'expected_salary' => 99999999]);
    $zedApp = CandidateApplication::factory()->create(['candidate_id' => $zed->id, 'requisition_id' => $requisition->id, 'recruiter_id' => $bob->id, 'current_stage' => CandidateStage::Screened]);

    $interview = Interview::factory()->create([
        'candidate_application_id' => $aliceApp->id, 'interviewer_id' => $bob->id, 'status' => InterviewStatus::Completed,
        'remarks' => 'PRIVATE-INTERVIEW-REMARK', 'meeting_link' => 'https://meet.example.invalid/private-room',
    ]);
    InterviewFeedback::factory()->create(['interview_id' => $interview->id, 'interviewer_id' => $bob->id,
        'feedback' => 'Strong on Laravel. PRIVATE-CANDIDATE-ALICE shared PRIVATE-ALICE@example.invalid and 9999912345.']);
    Offer::factory()->create([
        'candidate_application_id' => $aliceApp->id, 'offered_ctc' => 99999999, 'fixed_salary' => 88888888, 'remarks' => 'PRIVATE-OFFER-REMARK',
        'status' => OfferStatus::Released, 'offer_date' => now()->subDay(), 'offer_expiry' => now()->addDays(2),
    ]);
    CandidateJoining::factory()->create(['candidate_application_id' => $aliceApp->id, 'expected_doj' => now()->subDays(2)]);
    HiringOutcome::factory()->create(['requisition_id' => $requisition->id, 'candidate_application_id' => $zedApp->id]);
    RecruitmentFollowup::factory()->create(['candidate_application_id' => $aliceApp->id, 'recruiter_id' => $bob->id, 'followup_date' => now()->subDays(3), 'remarks' => 'PRIVATE-FOLLOWUP-REMARK']);
    app(CandidateTimelineService::class)->record($alice->id, TimelineEventType::Note, 'PRIVATE-NOTE-TEXT', related: ['application' => $aliceApp]);
    $pool = TalentPool::factory()->create(['visibility' => TalentPoolVisibility::Organization]);
    TalentPoolMembership::factory()->create(['talent_pool_id' => $pool->id, 'candidate_id' => $alice->id]);
    HiringRisk::query()->create([
        'type' => HiringRiskType::JoiningRisk, 'severity' => 'high', 'status' => 'open', 'requisition_id' => $requisition->id,
        'candidate_application_id' => $aliceApp->id, 'title' => 'Joining at risk — PRIVATE-CANDIDATE-ALICE',
        'description' => 'PRIVATE-CANDIDATE-ALICE has not confirmed.', 'detector_version' => 'risk-radar/1', 'first_detected_at' => now(), 'last_seen_at' => now(),
    ]);
    app(HiringMemoryService::class)->captureRequisitionOutcome($requisition, 'closed');
    app(RoleDnaService::class)->currentVersionFor($requisition);
    AiKnowledgeArticle::factory()->create(['is_published' => true, 'title' => 'Leave policy', 'content' => 'Employees get 20 days of leave.']);

    $this->world = [
        'requisition' => $requisition->id, 'alice' => $alice->id, 'aliceApp' => $aliceApp->id, 'zed' => $zed->id, 'zedApp' => $zedApp->id,
        'bob' => $bob->id, 'carol' => $carol->id, 'pool' => $pool->id, 'reason' => RecruitmentRejectionReason::factory()->create()->id,
        'refs' => ['alice' => $alice->candidate_code, 'aliceApp' => $aliceApp->application_code, 'requisition' => $requisition->code],
    ];
});

/**
 * Tool name => [bound arguments closure, expected reference in the provider payload (optional)].
 *
 * @return array<string, array{0: Closure, 1: string|null}>
 */
function toolContractCases(): array
{
    return [
        'search_candidates' => [fn () => ['query' => 'Laravel'], 'alice'],
        'get_candidate' => [fn () => ['candidate_id' => test()->world['alice']], 'alice'],
        'list_talent_pools' => [fn () => ['talent_pool_id' => test()->world['pool']], 'alice'],
        'compare_candidates' => [fn () => ['candidate_ids' => [test()->world['alice'], test()->world['zed']]], 'alice'],
        'summarize_candidate' => [fn () => ['candidate_id' => test()->world['alice']], 'alice'],
        'find_stuck_candidates' => [fn () => ['days' => 1], 'alice'],
        'find_duplicate_candidates' => [fn () => ['candidate_id' => test()->world['alice']], 'alice'],
        'get_candidate_timeline' => [fn () => ['application_id' => test()->world['aliceApp']], 'alice'],
        'list_overdue_followups' => [fn () => [], 'aliceApp'],
        'recommend_next_step' => [fn () => ['application_id' => test()->world['aliceApp']], 'alice'],
        'search_requisitions' => [fn () => [], 'requisition'],
        'get_requisition' => [fn () => ['requisition_id' => test()->world['requisition']], 'requisition'],
        'get_requisition_pipeline' => [fn () => ['requisition_id' => test()->world['requisition']], 'requisition'],
        'find_at_risk_requisitions' => [fn () => [], null],
        'generate_jd' => [fn () => ['title' => 'Senior Laravel Developer', 'skills' => 'Laravel, PHP'], null],
        'improve_jd' => [fn () => ['job_description' => 'We need a Laravel developer.'], null],
        'analyze_funnel' => [fn () => [], null],
        'analyze_sources' => [fn () => [], null],
        'time_to_hire' => [fn () => [], null],
        'forecast_hiring' => [fn () => ['target_hires' => 3], null],
        'generate_dashboard_insights' => [fn () => [], null],
        'get_recruiter_performance' => [fn () => ['employee_id' => test()->world['bob']], null],
        'compare_recruiters' => [fn () => ['employee_ids' => [test()->world['bob'], test()->world['carol']]], null],
        'find_inactive_recruiters' => [fn () => ['days' => 1], null],
        'generate_interview_questions' => [fn () => ['role' => 'Laravel Developer', 'candidate_id' => test()->world['alice']], null],
        'generate_interview_plan' => [fn () => ['role' => 'Laravel Developer'], null],
        'search_interviews' => [fn () => [], 'aliceApp'],
        'summarize_interview_feedback' => [fn () => ['application_id' => test()->world['aliceApp']], 'alice'],
        'analyze_offers' => [fn () => [], null],
        'search_offers' => [fn () => [], 'aliceApp'],
        'analyze_joining_conversion' => [fn () => [], null],
        'find_joining_risks' => [fn () => [], 'aliceApp'],
        'search_knowledge_base' => [fn () => ['query' => 'leave policy'], null],
        'web_research' => [fn () => ['topic' => 'Laravel developer salary benchmarks', 'location' => 'Pune'], null],
        'build_recruitment_plan' => [fn () => ['target_hires' => 2, 'days' => 30, 'role' => 'Laravel Developer', 'location' => 'Pune'], null],
        'assign_candidates_to_recruiter' => [fn () => ['application_ids' => [test()->world['aliceApp']], 'recruiter_employee_id' => test()->world['bob']], null],
        'move_candidates_stage' => [fn () => ['application_ids' => [test()->world['zedApp']], 'stage' => 'shortlisted', 'remarks' => 'Moved after review'], null],
        'reject_candidates' => [fn () => ['application_ids' => [test()->world['zedApp']], 'rejection_reason_id' => test()->world['reason'], 'remarks' => 'Not a fit'], null],
        'create_followup' => [fn () => ['application_id' => test()->world['aliceApp'], 'followup_type' => 'call', 'followup_date' => now()->addDay()->toIso8601String(), 'remarks' => 'Call back'], null],
        'schedule_interview' => [fn () => ['application_id' => test()->world['aliceApp'], 'interviewer_employee_id' => test()->world['bob'], 'scheduled_at' => now()->addDays(2)->toIso8601String(), 'mode' => 'video_call'], 'alice'],
        'draft_candidate_email' => [fn () => ['candidate_id' => test()->world['alice'], 'purpose' => 'interview invitation'], 'alice'],
        'send_candidate_email' => [fn () => ['candidate_id' => test()->world['alice'], 'subject' => 'Next steps', 'body' => 'Hi {{candidate.first_name}}, thank you.'], 'alice'],
        'get_role_dna' => [fn () => ['requisition_id' => test()->world['requisition']], 'requisition'],
        'explain_talent_signal' => [fn () => ['application_id' => test()->world['aliceApp']], 'aliceApp'],
        'get_hiring_health' => [fn () => ['requisition_id' => test()->world['requisition']], 'requisition'],
        'list_hiring_risks' => [fn () => [], 'aliceApp'],
        'rediscover_talent' => [fn () => ['requisition_id' => test()->world['requisition']], 'requisition'],
        'get_hiring_memory' => [fn () => ['requisition_id' => test()->world['requisition']], 'requisition'],
        'summarize_hiring_outcomes' => [fn () => ['requisition_id' => test()->world['requisition']], 'requisition'],
    ];
}

test('every registered AI tool has a privacy contract case', function (): void {
    $registered = collect(app(ToolRegistry::class)->all())->map(fn ($tool) => $tool->name())->values()->all();

    expect($registered)->toHaveCount(49)
        ->and(array_keys(toolContractCases()))->toEqualCanonicalizing($registered);
});

test('the provider and AI persistence never receive sentinel personal data', function (string $tool): void {
    [$arguments, $expectedRef] = toolContractCases()[$tool];
    $provider = new PrivacyRecordingLlmProvider($tool, $arguments->call($this));
    app()->instance(LLMProviderInterface::class, $provider);
    $conversation = AiConversation::factory()->create(['user_id' => $this->user->id, 'context_type' => 'candidate', 'context_id' => $this->world['alice']]);

    $turn = app(AiOrchestrator::class)->ask($conversation, 'Please help with this.', $this->user);

    foreach ($turn['pending'] as $pending) {
        app(ActionExecutor::class)->approve($pending, $this->user);
        app(AiOrchestrator::class)->continueTurn($conversation->fresh(), $this->user);
    }

    $call = AiToolCall::query()->where('tool_name', $tool)->sole();
    $payload = $provider->payload().json_encode($this->embeddings->texts).json_encode($this->search->queries);
    $stored = json_encode([
        AiToolResult::query()->pluck('output'),
        AiActionLog::query()->get(['input', 'output', 'result_summary']),
        $conversation->messages()->where('role', AiMessageRole::Tool)->pluck('content'),
    ]);

    expect($call->status)->toBe(AiToolCallStatus::Executed, "{$tool} did not execute: ".json_encode($call->result?->error))
        ->and(count($provider->calls))->toBeGreaterThanOrEqual(2);

    foreach (TOOL_CONTRACT_SENTINELS as $sentinel) {
        expect(str_contains($payload, $sentinel))->toBeFalse("{$tool} sent {$sentinel} to the provider")
            ->and(str_contains((string) $stored, $sentinel))->toBeFalse("{$tool} persisted {$sentinel}");
    }

    if ($expectedRef !== null) {
        expect($payload)->toContain($this->world['refs'][$expectedRef]);
    }
})->with(array_keys(toolContractCases()));

test('each tool\'s own result carries no sentinel data, before any sanitizer runs (projection layer)', function (string $tool): void {
    [$arguments] = toolContractCases()[$tool];
    app()->instance(LLMProviderInterface::class, new PrivacyRecordingLlmProvider('none', []));

    $result = app(ToolRegistry::class)->find($tool)->handle($arguments->call($this), $this->user);
    $raw = (string) json_encode($result->toArray());

    expect($result->success)->toBeTrue("{$tool} failed: ".$result->error);

    foreach (TOOL_CONTRACT_SENTINELS as $sentinel) {
        $at = strpos($raw, $sentinel);
        expect($at)->toBeFalse("{$tool} returned {$sentinel} from handle(), after: ".($at === false ? '' : substr($raw, max(0, $at - 80), 80)));
    }
})->with(array_keys(toolContractCases()));
