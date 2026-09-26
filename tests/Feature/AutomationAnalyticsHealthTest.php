<?php

use App\Enums\AutomationExecutionStatus;
use App\Enums\RecruiterActionStatus;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Employee;
use App\Models\RecruiterAction;
use App\Models\User;
use App\Services\Automation\AutomationHealthService;
use App\Services\RecruitmentAnalyticsService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function healthRuns(AutomationRule $rule, AutomationExecutionStatus $status, int $count, array $attributes = []): void
{
    AutomationExecution::factory()->count($count)->create(['automation_rule_id' => $rule->id, 'status' => $status, ...$attributes]);
}

test('a healthy active rule reports healthy', function (): void {
    $rule = AutomationRule::factory()->active()->create();
    healthRuns($rule, AutomationExecutionStatus::Completed, 6);

    expect(app(AutomationHealthService::class)->overview())
        ->status->toBe(AutomationHealthService::HEALTHY)
        ->signals->toBe([]);
});

test('failure rate drives warning and failed health', function (): void {
    $warning = AutomationRule::factory()->active()->create();
    healthRuns($warning, AutomationExecutionStatus::Completed, 7);
    healthRuns($warning, AutomationExecutionStatus::Failed, 3);

    $failing = AutomationRule::factory()->active()->create();
    healthRuns($failing, AutomationExecutionStatus::Failed, 5);

    $health = app(AutomationHealthService::class);

    expect($health->forRule($warning)['status'])->toBe(AutomationHealthService::WARNING)
        ->and($health->forRule($failing)['status'])->toBe(AutomationHealthService::FAILED)
        ->and($health->overview()['status'])->toBe(AutomationHealthService::FAILED);
});

test('an active rule with a missing template is failed and an unconfigured provider is a warning', function (): void {
    $rule = AutomationRule::factory()->active()->withActions([['type' => 'send_communication', 'template_key' => 'nope', 'channels' => ['sms']]])->create();

    $signals = collect(app(AutomationHealthService::class)->forRule($rule)['signals']);

    expect($signals->pluck('severity')->all())->toContain(AutomationHealthService::FAILED, AutomationHealthService::WARNING)
        ->and($signals->pluck('message')->implode(' '))->toContain('Invalid template')->toContain('Provider unavailable');
});

test('loops, excessive retries and backlog raise warnings', function (): void {
    $rule = AutomationRule::factory()->active()->create();
    healthRuns($rule, AutomationExecutionStatus::Skipped, 1, ['skip_reason' => 'Loop prevented: x']);
    healthRuns($rule, AutomationExecutionStatus::Completed, 1, ['retry_count' => 3]);
    healthRuns($rule, AutomationExecutionStatus::Pending, 1, ['scheduled_for' => now()->subHour()]);

    $overview = app(AutomationHealthService::class)->overview();
    $messages = collect($overview['signals'])->pluck('message')->implode(' ');

    expect($overview['status'])->toBe(AutomationHealthService::WARNING)
        ->and($overview['backlog'])->toBe(1)
        ->and($messages)->toContain('loop prevention')->toContain('3 or more retries')->toContain('overdue');
});

test('automation analytics count runs, actions, escalations and resolution time, scoped by hierarchy', function (): void {
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    $user = User::factory()->create(['employee_id' => $manager->id])->assignRole('manager');
    $rule = AutomationRule::factory()->active()->create(['name' => 'Chaser']);

    healthRuns($rule, AutomationExecutionStatus::Completed, 3, ['recruiter_id' => $recruiter->id]);
    healthRuns($rule, AutomationExecutionStatus::Failed, 1, ['recruiter_id' => $recruiter->id]);
    healthRuns($rule, AutomationExecutionStatus::Completed, 5, ['recruiter_id' => Employee::factory()->create()->id]);

    $action = RecruiterAction::factory()->create(['owner_id' => $recruiter->id, 'automation_rule_id' => $rule->id, 'created_at' => now()->subHours(4)]);
    $action->forceFill(['status' => RecruiterActionStatus::Completed, 'completed_at' => now()])->save();
    RecruiterAction::factory()->create(['owner_id' => $recruiter->id]);

    $scoped = app(RecruitmentAnalyticsService::class)->automationAnalytics(now()->subDay(), now(), $user);
    $all = app(RecruitmentAnalyticsService::class)->automationAnalytics(now()->subDay(), now());

    expect($scoped)
        ->executions->toBe(4)
        ->completed->toBe(3)
        ->failed->toBe(1)
        ->actions_created->toBe(1)
        ->actions_completed->toBe(1)
        ->avg_resolution_hours->toBe(4.0)
        ->and($scoped['by_rule']->sole())->toMatchArray(['name' => 'Chaser', 'executions' => 4, 'failed' => 1, 'failure_rate' => 25.0])
        ->and($all['executions'])->toBe(9);
});

test('averages are empty rather than invented when nothing happened', function (): void {
    expect(app(RecruitmentAnalyticsService::class)->automationAnalytics(now()->subDay(), now()))
        ->executions->toBe(0)
        ->avg_resolution_hours->toBeNull();
});
