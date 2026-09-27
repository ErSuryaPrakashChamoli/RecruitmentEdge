<?php

use App\Enums\CandidateStage;
use App\Jobs\SummarizeOutcomeInsightJob;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interviewer;
use App\Models\OutcomeInsight;
use App\Models\User;
use App\Services\Identity\StaffAccessService;
use App\Services\Intelligence\IntelligenceAiService;
use App\Services\InterviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.7 (D8.7-014/015/016): every unit of work carries a correlation id; asynchronous audit
 * rows say what kind of actor acted and on whose behalf; queued AI work re-checks its requester.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('an artisan command gets a command correlation id and its audit rows say console', function (): void {
    Context::forget('request_id');
    event(new CommandStarting('governance:audit', new ArrayInput([]), new NullOutput));

    $row = AuditLog::record(Candidate::factory()->create(), 'probe', null, null);

    expect(Context::get('request_id'))->toStartWith('cmd:')
        ->and($row->request_id)->toBe(Context::get('request_id'))
        ->and($row->actor_kind)->toBe('console');
});

test('a queued job carries the dispatching correlation id and its audit rows say queue', function (): void {
    config(['queue.default' => 'database']);
    $candidate = Candidate::factory()->create();
    Context::add('request_id', 'req-dispatching-1');

    dispatch(function () use ($candidate): void {
        AuditLog::record($candidate, 'probe_from_job', null, null);
    });

    Context::forget('request_id');
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    $row = AuditLog::query()->where('action', 'probe_from_job')->sole();

    expect($row->request_id)->toBe('req-dispatching-1')
        ->and($row->actor_kind)->toBe('queue')
        ->and($row->user_id)->toBeNull();
});

test('automation acts on its owner\'s behalf, never as the signed-in person, and keeps its origin request id', function (): void {
    $manager = Employee::factory()->create();
    $managerUser = User::factory()->create(['employee_id' => $manager->id])->assignRole('manager');
    $rule = AutomationRule::factory()->active()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    User::factory()->create(['employee_id' => $recruiter->id])->assignRole('recruiter');
    actingAs($managerUser);
    Context::add('request_id', 'req-interview-7');

    app(InterviewService::class)->schedule(CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id, 'current_stage' => CandidateStage::Shortlisted]), ['interviewer_id' => Interviewer::factory()->create()->employee_id, 'scheduled_at' => now()->addDays(3), 'mode' => 'phone']);

    $execution = AutomationExecution::query()->where('automation_rule_id', $rule->id)->sole();
    $row = AuditLog::query()->where('auditable_type', AutomationExecution::class)->where('auditable_id', $execution->id)->where('action', 'automation_executed')->sole();

    expect($execution->origin_request_id)->toBe('req-interview-7')
        ->and($row->actor_kind)->toBe('automation')
        ->and($row->user_id)->toBeNull()
        ->and($row->on_behalf_of_user_id)->toBe($rule->owner_id);
});

test('a queued AI job whose requester lost access does nothing and says why', function (): void {
    $requester = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('vp_hr');
    $insight = OutcomeInsight::factory()->create();
    app(StaffAccessService::class)->suspend($requester, null, 'Extended leave');

    (new SummarizeOutcomeInsightJob($insight->id, $requester->id))->handle(app(IntelligenceAiService::class));

    $row = AuditLog::query()->where('action', 'ai_request_skipped')->sole();

    expect($row->actor_kind)->toBe('ai')
        ->and($row->changes['requested_by_user_id'])->toBe($requester->id)
        ->and($row->auditable_id)->toBe($insight->id);
});
