<?php

use App\Enums\AiRiskLevel;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\FollowupStatus;
use App\Enums\InterviewStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\AI\Tools\CandidateTools\ListOverdueFollowupsTool;
use App\Services\AI\Tools\CandidateTools\RecommendNextStepTool;
use App\Services\AI\Tools\InterviewTools\SearchInterviewsTool;
use App\Services\AI\Tools\JobTools\FindAtRiskRequisitionsTool;
use App\Services\AI\Tools\JobTools\GetRequisitionPipelineTool;
use App\Services\AI\Tools\OfferTools\SearchOffersTool;
use App\Services\AI\Tools\ToolRegistry;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->manager = Employee::factory()->create();
    $this->teamRecruiter = Employee::factory()->reportingTo($this->manager)->create();
    $this->outsider = Employee::factory()->create();

    $this->user = User::factory()->create(['employee_id' => $this->manager->id]);
    $this->user->assignRole('manager');
});

test('the five new tools are registered, with recommend_next_step at the advisory risk level', function (): void {
    $registry = app(ToolRegistry::class);
    $names = collect($registry->all())->map(fn ($tool) => $tool->name())->all();

    expect(count($names))->toBeGreaterThanOrEqual(41)
        ->and($names)->toContain('recommend_next_step', 'search_interviews', 'search_offers', 'get_requisition_pipeline', 'list_overdue_followups')
        ->and($registry->find('recommend_next_step')->riskLevel())->toBe(AiRiskLevel::Recommend);
});

test('search_interviews only returns interviews within the caller\'s hierarchy and honours status and date filters', function (): void {
    $upcoming = Interview::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $this->teamRecruiter->id])->id,
        'status' => InterviewStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);
    $completed = Interview::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $this->teamRecruiter->id])->id,
        'status' => InterviewStatus::Completed,
        'scheduled_at' => now()->subDay(),
    ]);
    Interview::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $this->outsider->id])->id,
        'status' => InterviewStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);

    $tool = app(SearchInterviewsTool::class);

    $byStatus = $tool->handle(['status' => 'scheduled'], $this->user);
    $byDate = $tool->handle(['start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->subDay()->toDateString()], $this->user);

    expect(collect($byStatus->data['interviews'])->pluck('interview_id')->all())->toBe([$upcoming->id])
        ->and(collect($byDate->data['interviews'])->pluck('interview_id')->all())->toBe([$completed->id])
        ->and($tool->handle(['status' => 'not-a-status'], $this->user)->success)->toBeFalse();
});

test('search_offers only returns offers within the caller\'s hierarchy and honours status and date filters', function (): void {
    $released = Offer::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $this->teamRecruiter->id])->id,
        'status' => OfferStatus::Released,
        'offer_date' => now()->subDays(3),
    ]);
    $olderAccepted = Offer::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $this->teamRecruiter->id])->id,
        'status' => OfferStatus::Accepted,
        'offer_date' => now()->subDays(40),
    ]);
    Offer::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $this->outsider->id])->id,
        'status' => OfferStatus::Released,
        'offer_date' => now()->subDays(3),
    ]);

    $tool = app(SearchOffersTool::class);

    $byStatus = $tool->handle(['status' => 'released'], $this->user);
    $recent = $tool->handle(['start_date' => now()->subDays(10)->toDateString()], $this->user);
    $all = $tool->handle([], $this->user);

    expect(collect($byStatus->data['offers'])->pluck('offer_id')->all())->toBe([$released->id])
        ->and(collect($recent->data['offers'])->pluck('offer_id')->all())->toBe([$released->id])
        ->and(collect($all->data['offers'])->pluck('offer_id')->sort()->values()->all())->toBe(collect([$released->id, $olderAccepted->id])->sort()->values()->all());
});

test('get_requisition_pipeline counts only the caller\'s team applications by stage and status, and hides unrelated requisitions', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id, 'status' => RequisitionStatus::Open]);

    CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'recruiter_id' => $this->teamRecruiter->id, 'current_stage' => CandidateStage::Shortlisted]);
    CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'recruiter_id' => $this->teamRecruiter->id, 'current_stage' => CandidateStage::Shortlisted, 'status' => ApplicationStatus::Rejected]);
    CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'recruiter_id' => $this->outsider->id, 'current_stage' => CandidateStage::Selected]);

    $unrelated = RecruitmentRequisition::factory()->create(['manager_id' => $this->outsider->id, 'status' => RequisitionStatus::Open]);

    $tool = app(GetRequisitionPipelineTool::class);
    $result = $tool->handle(['requisition_id' => $requisition->id], $this->user);
    $byStage = collect($result->data['by_stage']);

    expect($result->success)->toBeTrue()
        ->and($result->data['total_applications'])->toBe(2)
        ->and($byStage->firstWhere('stage', 'Shortlisted'))->toBe(['stage' => 'Shortlisted', 'total' => 2, 'active' => 1])
        ->and($byStage->firstWhere('stage', 'Selected')['total'])->toBe(0)
        ->and($result->data['by_status']['Rejected'])->toBe(1)
        ->and($tool->handle(['requisition_id' => $unrelated->id], $this->user)->success)->toBeFalse();
});

test('list_overdue_followups returns only pending, past-due follow-ups within the caller\'s hierarchy', function (): void {
    $overdue = RecruitmentFollowup::factory()->create(['recruiter_id' => $this->teamRecruiter->id, 'followup_date' => now()->subDays(3), 'status' => FollowupStatus::Pending]);
    RecruitmentFollowup::factory()->create(['recruiter_id' => $this->teamRecruiter->id, 'followup_date' => now()->addDay(), 'status' => FollowupStatus::Pending]);
    RecruitmentFollowup::factory()->create(['recruiter_id' => $this->teamRecruiter->id, 'followup_date' => now()->subDay(), 'status' => FollowupStatus::Completed]);
    RecruitmentFollowup::factory()->create(['recruiter_id' => $this->outsider->id, 'followup_date' => now()->subDays(2), 'status' => FollowupStatus::Pending]);

    $result = app(ListOverdueFollowupsTool::class)->handle([], $this->user);

    expect(collect($result->data['overdue_followups'])->pluck('followup_id')->all())->toBe([$overdue->id])
        ->and($result->data['total_overdue'])->toBe(1)
        ->and($result->data['overdue_followups'][0]['days_overdue'])->toBe(3);
});

test('recommend_next_step suggests scheduling an interview for a fresh shortlisted application and changes nothing', function (): void {
    $application = CandidateApplication::factory()->create([
        'recruiter_id' => $this->teamRecruiter->id,
        'current_stage' => CandidateStage::Shortlisted,
        'last_activity_at' => now(),
    ]);

    $result = app(RecommendNextStepTool::class)->handle(['application_id' => $application->id], $this->user);

    expect($result->success)->toBeTrue()
        ->and($result->data['advisory'])->toBeTrue()
        ->and($result->data['recommended_action']['action'])->toContain('Schedule the first interview')
        ->and($application->fresh()->current_stage)->toBe(CandidateStage::Shortlisted)
        ->and(Interview::query()->count())->toBe(0)
        ->and(RecruitmentFollowup::query()->count())->toBe(0);
});

test('recommend_next_step puts overdue follow-ups and missing interview feedback ahead of the stage default', function (): void {
    $application = CandidateApplication::factory()->create([
        'recruiter_id' => $this->teamRecruiter->id,
        'current_stage' => CandidateStage::Interview1,
        'last_activity_at' => now(),
    ]);
    RecruitmentFollowup::factory()->create([
        'candidate_application_id' => $application->id,
        'recruiter_id' => $this->teamRecruiter->id,
        'followup_date' => now()->subDays(2),
        'status' => FollowupStatus::Pending,
    ]);
    Interview::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => InterviewStatus::Completed,
        'scheduled_at' => now()->subDay(),
    ]);

    $result = app(RecommendNextStepTool::class)->handle(['application_id' => $application->id], $this->user);
    $actions = collect([$result->data['recommended_action'], ...$result->data['other_signals']])->pluck('action')->implode(' | ');

    expect($result->data['recommended_action']['priority'])->toBe('high')
        ->and($actions)->toContain('overdue')
        ->and($actions)->toContain('feedback');
});

test('recommend_next_step resolves a candidate to their visible application and refuses out-of-scope ones', function (): void {
    $visible = CandidateApplication::factory()->create(['recruiter_id' => $this->teamRecruiter->id, 'current_stage' => CandidateStage::Sourced, 'last_activity_at' => now()]);
    $hidden = CandidateApplication::factory()->create(['recruiter_id' => $this->outsider->id]);
    $closed = CandidateApplication::factory()->create(['recruiter_id' => $this->teamRecruiter->id, 'status' => ApplicationStatus::Rejected]);

    $tool = app(RecommendNextStepTool::class);

    $byCandidate = $tool->handle(['candidate_id' => $visible->candidate_id], $this->user);

    expect($byCandidate->success)->toBeTrue()
        ->and($byCandidate->data['application_id'])->toBe($visible->id)
        ->and($tool->handle(['application_id' => $hidden->id], $this->user)->success)->toBeFalse()
        ->and($tool->handle(['candidate_id' => $hidden->candidate_id], $this->user)->success)->toBeFalse()
        ->and($tool->handle(['application_id' => $closed->id], $this->user)->data['recommended_action']['action'])->toContain('No action needed');
});

test('find_at_risk_requisitions reports overdue requisitions out of all open ones, not just the overdue subset', function (): void {
    RecruitmentSetting::put('vacancy_ageing_alert_days', '30', 'int');

    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays(45)]);
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays(5)]);

    $chro = User::factory()->create();
    $chro->assignRole('chro');

    $result = app(FindAtRiskRequisitionsTool::class)->handle([], $chro);

    expect($result->data['requisitions'])->toHaveCount(2)
        ->and($result->summary)->toBe('1 of 2 open requisition(s) are overdue on vacancy ageing.');
});
