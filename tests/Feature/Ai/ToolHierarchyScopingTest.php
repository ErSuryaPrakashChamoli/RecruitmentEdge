<?php

use App\Enums\JoiningStatus;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Tools\ActionTools\DraftCandidateEmailTool;
use App\Services\AI\Tools\ActionTools\SendCandidateEmailTool;
use App\Services\AI\Tools\CandidateTools\FindDuplicateCandidatesTool;
use App\Services\AI\Tools\InterviewTools\GenerateInterviewQuestionsTool;
use App\Services\AI\Tools\RecruitmentTools\AnalyzeSourcesTool;
use App\Services\AI\Tools\RecruitmentTools\TimeToHireTool;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->manager = Employee::factory()->create();
    $this->teamRecruiter = Employee::factory()->reportingTo($this->manager)->create();
    $this->outsider = Employee::factory()->create();

    $this->user = User::factory()->create(['employee_id' => $this->manager->id]);
    $this->user->assignRole('manager');

    $this->teamCandidate = fn (array $attributes = []): Candidate => CandidateApplication::factory()
        ->create(['recruiter_id' => $this->teamRecruiter->id, 'candidate_id' => Candidate::factory()->create($attributes)->id])
        ->candidate;

    $this->outsiderCandidate = fn (array $attributes = []): Candidate => CandidateApplication::factory()
        ->create(['recruiter_id' => $this->outsider->id, 'candidate_id' => Candidate::factory()->create($attributes)->id])
        ->candidate;
});

test('find_duplicate_candidates only reports duplicates the caller can see, and refuses an out-of-scope subject', function (): void {
    $subject = ($this->teamCandidate)(['mobile' => '9000000001']);
    $visibleDuplicate = ($this->teamCandidate)(['mobile' => '9000000001']);
    $hiddenDuplicate = ($this->outsiderCandidate)(['mobile' => '9000000001']);

    $tool = app(FindDuplicateCandidatesTool::class);
    $ids = collect($tool->handle(['candidate_id' => $subject->id], $this->user)->data['duplicates'])->pluck('candidate_id');

    expect($ids->all())->toContain($visibleDuplicate->id)
        ->and($ids->all())->not->toContain($hiddenDuplicate->id)
        ->and($tool->handle(['candidate_id' => $hiddenDuplicate->id], $this->user)->success)->toBeFalse();
});

test('draft_candidate_email refuses a candidate outside the caller\'s hierarchy without calling the model', function (): void {
    $provider = new ScriptedLlmProvider([ScriptedLlmProvider::text("Subject: Interview invitation\n\nHello!")]);
    $this->app->instance(LLMProviderInterface::class, $provider);

    $tool = app(DraftCandidateEmailTool::class);

    $hidden = $tool->handle(['candidate_id' => ($this->outsiderCandidate)()->id, 'purpose' => 'interview invitation'], $this->user);

    expect($hidden->success)->toBeFalse()
        ->and($hidden->error)->toContain('not visible')
        ->and($provider->calls)->toBe([]);

    $visible = $tool->handle(['candidate_id' => ($this->teamCandidate)()->id, 'purpose' => 'interview invitation'], $this->user);

    expect($visible->success)->toBeTrue()
        ->and($visible->data['subject'])->toBe('Interview invitation');
});

test('send_candidate_email refuses a candidate outside the approver\'s hierarchy and sends nothing', function (): void {
    Mail::fake();

    $result = app(SendCandidateEmailTool::class)->handle([
        'candidate_id' => ($this->outsiderCandidate)()->id,
        'subject' => 'Hello',
        'body' => 'Body',
    ], $this->user);

    expect($result->success)->toBeFalse();
    Mail::assertNothingSent();
});

test('generate_interview_questions refuses to tailor questions to a candidate outside the caller\'s hierarchy', function (): void {
    $provider = new ScriptedLlmProvider;
    $this->app->instance(LLMProviderInterface::class, $provider);

    $result = app(GenerateInterviewQuestionsTool::class)->handle([
        'role' => 'Laravel Developer',
        'candidate_id' => ($this->outsiderCandidate)()->id,
    ], $this->user);

    expect($result->success)->toBeFalse()
        ->and($provider->calls)->toBe([]);
});

test('analyze_sources counts only candidates within the caller\'s hierarchy', function (): void {
    $source = CandidateSource::factory()->create();

    ($this->teamCandidate)(['source_id' => $source->id]);
    ($this->outsiderCandidate)(['source_id' => $source->id]);

    $row = collect(app(AnalyzeSourcesTool::class)->handle([], $this->user)->data['sources'])->firstWhere('source', $source->name);

    expect($row['sourced'])->toBe(1);
});

test('time_to_hire counts only successful joins within the caller\'s hierarchy', function (): void {
    foreach ([$this->teamRecruiter, $this->outsider] as $recruiter) {
        CandidateJoining::factory()->create([
            'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
            'status' => JoiningStatus::Joined,
            'actual_doj' => now()->subDays(5),
        ]);
    }

    $result = app(TimeToHireTool::class)->handle([], $this->user);

    expect($result->data['successful_joins'])->toBe(1);
});
