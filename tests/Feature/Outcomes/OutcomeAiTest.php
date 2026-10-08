<?php

use App\Enums\IntelligenceAiStatus;
use App\Enums\OutcomeInsightStatus;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeType;
use App\Jobs\SummarizeOutcomeInsightJob;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\HiringOutcome;
use App\Models\OutcomeInsight;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Tools\IntelligenceTools\SummarizeHiringOutcomesTool;
use App\Services\Intelligence\IntelligenceAiService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->user = User::factory()->create(['name' => 'PRIVATE-REVIEWER-NAME', 'employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->insight = OutcomeInsight::factory()->create([
        'designation_id' => Designation::factory()->create(['name' => 'Backend Developer'])->id,
        'reviewed_by' => $this->user->id,
        'review_reason' => 'PRIVATE-REVIEW-NOTE',
        'evidence' => ['skill' => 'skill:laravel', 'skill_label' => 'Laravel', 'observed' => 5, 'observed_active' => 4, 'active_with_skill' => 4, 'with_skill' => ['observed' => 4, 'active' => 4]],
        'source_refs' => ['outcomes' => [987654321], 'snapshots' => [876543219]],
    ]);
});

function outcomeAiNarrate(OutcomeInsight $insight, string $reply): ScriptedLlmProvider
{
    $provider = new ScriptedLlmProvider([ScriptedLlmProvider::text($reply)]);
    app()->instance(LLMProviderInterface::class, $provider);
    app(IntelligenceAiService::class)->summarizeInsight($insight->fresh());

    return $provider;
}

test('an insight narrative prompt carries aggregate figures and role labels only', function (): void {
    $provider = outcomeAiNarrate($this->insight, 'Among 5 observed hires, 4 listed Laravel. The sample is small and observational.');
    $prompt = (string) json_encode(array_map(fn ($message) => $message->content, $provider->calls[0]['messages']));

    expect($prompt)->toContain('Backend Developer')
        ->toContain('Laravel')
        ->toContain('active_with_skill')
        ->not->toContain('987654321')
        ->not->toContain('876543219')
        ->not->toContain('PRIVATE-REVIEWER-NAME')
        ->not->toContain('PRIVATE-REVIEW-NOTE')
        ->and($this->insight->fresh())
        ->ai_status->toBe(IntelligenceAiStatus::Available)
        ->ai_summary->toContain('observational')
        ->ai_model->not->toBeNull()
        ->status->toBe(OutcomeInsightStatus::Review);
});

test('a narrative with causal or unfair wording is discarded and the insight is unchanged', function (string $reply, string $reason): void {
    outcomeAiNarrate($this->insight, $reply);

    expect($this->insight->fresh())
        ->ai_status->toBe(IntelligenceAiStatus::Failed)
        ->ai_summary->toBeNull()
        ->status->toBe(OutcomeInsightStatus::Review)
        ->sample_size->toBe(5)
        ->and(AuditLog::query()->where('action', 'outcome_insight_ai_rejected')->sole()->changes['reason'])->toBe($reason);
})->with([
    'causal' => ['Hires stay because they know Laravel.', 'causal_language'],
    'predictive' => ['Laravel predicts who will stay.', 'causal_language'],
    'protected characteristic' => ['Younger hires with Laravel were observed active.', 'fairness'],
]);

test('without a configured provider the request is unavailable and nothing is queued', function (): void {
    Queue::fake();
    $this->mock(LLMProviderInterface::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(false));

    $status = app(IntelligenceAiService::class)->requestInsightSummary($this->insight, $this->user);

    expect($status)->toBe(IntelligenceAiStatus::Unavailable)
        ->and($this->insight->fresh()->status)->toBe(OutcomeInsightStatus::Review);
    Queue::assertNotPushed(SummarizeOutcomeInsightJob::class);
});

test('the outcomes tool returns aggregates for visible requisitions only', function (): void {
    $manager = Employee::factory()->create();
    $managerUser = User::factory()->create(['employee_id' => $manager->id])->assignRole('manager');
    $mine = RecruitmentRequisition::factory()->create(['manager_id' => $manager->id]);
    $theirs = RecruitmentRequisition::factory()->create();

    foreach ([$mine, $mine, $mine, $theirs] as $requisition) {
        $application = CandidateApplication::factory()->create(['requisition_id' => $requisition->id]);
        $application->candidate->update(['full_name' => 'PRIVATE-CANDIDATE-NAME']);
        HiringOutcome::factory()->create(['outcome_type' => OutcomeType::Joined, 'result' => OutcomeResult::Occurred, 'requisition_id' => $requisition->id, 'candidate_application_id' => $application->id]);
    }

    $tool = app(SummarizeHiringOutcomesTool::class);
    $result = $tool->handle([], $managerUser);

    expect($result->success)->toBeTrue()
        ->and($result->data['joining']['counts']['joined'])->toBe(3)
        ->and($result->data['joining']['join_rate_pct'])->toEqual(100.0)
        ->and(json_encode($result->data))->not->toContain('PRIVATE-CANDIDATE-NAME')
        ->and($tool->handle(['requisition_id' => $theirs->id], $managerUser)->success)->toBeFalse();
});
