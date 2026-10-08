<?php

use App\Enums\CandidateStage;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\Tools\CandidateTools\CompareCandidatesTool;
use App\Services\AI\Tools\CandidateTools\GetCandidateTool;
use App\Services\AI\Tools\CandidateTools\SearchCandidatesTool;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a manager cannot retrieve a candidate belonging to a different, unrelated team through get_candidate', function (): void {
    $myTeamManager = Employee::factory()->create();
    $myTeamRecruiter = Employee::factory()->reportingTo($myTeamManager)->create();
    $otherTeamRecruiter = Employee::factory()->create();

    $myApplication = CandidateApplication::factory()->create(['recruiter_id' => $myTeamRecruiter->id]);
    $otherApplication = CandidateApplication::factory()->create(['recruiter_id' => $otherTeamRecruiter->id]);

    $user = User::factory()->create(['employee_id' => $myTeamManager->id]);
    $user->assignRole('manager');

    $tool = app(GetCandidateTool::class);

    $ownResult = $tool->handle(['candidate_id' => $myApplication->candidate_id], $user);
    $otherResult = $tool->handle(['candidate_id' => $otherApplication->candidate_id], $user);

    expect($ownResult->success)->toBeTrue()
        ->and($otherResult->success)->toBeFalse();
});

test('search_candidates only returns candidates whose application recruiter is within the caller\'s hierarchy', function (): void {
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $visibleApplication = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $visibleApplication->candidate->update(['full_name' => 'Zebra Visible Candidate']);

    $hiddenApplication = CandidateApplication::factory()->create(['recruiter_id' => $outsider->id]);
    $hiddenApplication->candidate->update(['full_name' => 'Zebra Hidden Candidate']);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');

    $tool = app(SearchCandidatesTool::class);
    $result = $tool->handle(['query' => 'Zebra'], $user);

    $ids = collect($result->data['candidates'])->pluck('id');

    expect($ids)->toContain($visibleApplication->candidate_id)
        ->and($ids)->not->toContain($hiddenApplication->candidate_id);
});

test('a CHRO with hierarchy.view-all sees every candidate regardless of recruiter', function (): void {
    $chroEmployee = Employee::factory()->create();
    $someRecruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $someRecruiter->id]);

    $user = User::factory()->create(['employee_id' => $chroEmployee->id]);
    $user->assignRole('chro');

    $tool = app(GetCandidateTool::class);
    $result = $tool->handle(['candidate_id' => $application->candidate_id], $user);

    expect($result->success)->toBeTrue();
});

test('compare_candidates compares the latest application the caller may see, never another team\'s (P810-AI-11)', function (): void {
    $manager = Employee::factory()->create();
    $myRecruiter = Employee::factory()->reportingTo($manager)->create();
    $otherRecruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $manager->id])->assignRole('manager');

    $mine = CandidateApplication::factory()->create(['recruiter_id' => $myRecruiter->id, 'current_stage' => CandidateStage::Screened, 'application_date' => now()->subMonth()]);
    CandidateApplication::factory()->create(['candidate_id' => $mine->candidate_id, 'recruiter_id' => $otherRecruiter->id, 'current_stage' => CandidateStage::OfferReleased, 'application_date' => now()]);
    $second = CandidateApplication::factory()->create(['recruiter_id' => $myRecruiter->id, 'current_stage' => CandidateStage::Interview1]);

    $result = app(CompareCandidatesTool::class)->handle(['candidate_ids' => [$mine->candidate_id, $second->candidate_id]], $user);
    $row = collect($result->data['comparison'])->firstWhere('id', $mine->candidate_id);

    expect($result->success)->toBeTrue()
        ->and($row['current_stage'])->toBe(CandidateStage::Screened->label());
});
