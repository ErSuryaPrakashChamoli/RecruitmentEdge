<?php

use App\Enums\AutomationExecutionStatus;
use App\Enums\Entitlement;
use App\Enums\RequisitionStatus;
use App\Filament\Pages\AiCopilot;
use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use App\Filament\Resources\AutomationRules\Pages\ListAutomationRules;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Jobs\AI\IndexAiDocumentJob;
use App\Models\AiDocument;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Export;
use App\Models\JobPosting;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\AI\Gateway\AiGateway;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationRuleService;
use App\Services\Distribution\JobDistributionService;
use App\Services\Entitlements\EntitlementDenied;
use App\Services\Export\ReportExportService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Commercial\CommercialWorld;

/*
 * SaaS-3: a feature outside the tenant's plan is refused where the work happens — the AI gateway,
 * the automation services and engine, distribution, exports — and only then hidden in the panel.
 * Permissions still apply on top; the plan never grants what a role does not.
 */
beforeEach(function (): void {
    $this->world = CommercialWorld::build();
});

test('every AI call is refused for a tenant without the AI assistant, before anything is sent', function (): void {
    $gateway = app(AiGateway::class);

    expect(fn () => $this->world->in('trialStarter', fn () => $gateway->embed(['hello'])))->toThrow(EntitlementDenied::class, 'AI assistant')
        ->and(fn () => $this->world->in('trialStarter', fn () => $gateway->generate([], [], 'chat')))->toThrow(EntitlementDenied::class)
        ->and(fn () => $this->world->in('unentitled', fn () => $gateway->research('anything')))->toThrow(EntitlementDenied::class)
        ->and(fn () => $this->world->in('suspended', fn () => $gateway->embed(['hello'])))->toThrow(EntitlementDenied::class, 'not active');
});

test('the Copilot is hidden and refused without the AI assistant, whatever the role', function (): void {
    $this->actInTenant($this->world->tenant('trialStarter'));
    $this->actingAs($this->world->admins['trialStarter']->fresh());

    expect(AiCopilot::canAccess())->toBeFalse();
    $this->get('/admin/alpha-trial/ai-copilot')->assertForbidden();

    $this->actInTenant($this->world->tenant('growth'));
    $this->actingAs($this->world->admins['growth']->fresh());

    expect(AiCopilot::canAccess())->toBeTrue();
});

test('AI work queued for a tenant that has lost the AI assistant is skipped, not run and not failed', function (): void {
    $document = $this->world->in('trialStarter', fn () => AiDocument::factory()->create());

    $this->world->in('trialStarter', fn () => IndexAiDocumentJob::dispatchSync($document->id));

    expect($this->world->in('trialStarter', fn () => $document->fresh()->status))->toBe($document->status);
});

test('automation: building and switching on rules is refused, the engine creates and runs nothing', function (): void {
    $admin = $this->world->admins['trialStarter']->fresh();
    $rule = $this->world->in('trialStarter', fn () => AutomationRule::factory()->create(['owner_id' => $admin->id, 'created_by' => $admin->id]));

    expect(fn () => $this->world->in('trialStarter', fn () => app(AutomationRuleService::class)->createFromTemplate('interview_confirmation_reminder', $admin)))->toThrow(EntitlementDenied::class)
        ->and(fn () => $this->world->in('trialStarter', fn () => app(AutomationRuleService::class)->activate($rule, $admin)))->toThrow(EntitlementDenied::class)
        ->and($this->world->in('trialStarter', fn () => app(AutomationEngine::class)->processDue(50)))->toBe(['dispatched' => 0, 'stale_failed' => 0, 'stale_cancelled' => 0])
        ->and($this->world->in('trialStarter', fn () => app(AutomationEngine::class)->trigger('requisition.opened', RecruitmentRequisition::factory()->create())))->toBe(0);

    // Pausing and archiving stay possible: a downgrade can always wind automation down.
    expect($this->world->in('trialStarter', fn () => app(AutomationRuleService::class)->archive($rule, $admin, 'Not in plan'))->status->value)->toBe('archived');
});

test('a run waiting when automation leaves the plan is cancelled visibly, never run', function (): void {
    $admin = $this->world->admins['growth']->fresh();
    $execution = $this->world->in('growth', fn () => AutomationExecution::factory()->create(['status' => AutomationExecutionStatus::Pending]));
    app(EntitlementOverrideService::class)->set($this->world->tenant('growth'), Entitlement::AutomationRules, false, 'Paused for the customer');

    $this->world->in('growth', fn () => app(AutomationEngine::class)->run($execution->id));

    expect($this->world->in('growth', fn () => $execution->fresh()->status))->toBe(AutomationExecutionStatus::Cancelled)
        ->and($this->world->in('growth', fn () => $execution->fresh()->skip_reason))->toContain('not included')
        ->and($admin->exists)->toBeTrue();
});

test('the automation module is hidden and refused — navigation, URL and Livewire', function (): void {
    $this->actInTenant($this->world->tenant('trialStarter'));
    $this->actingAs($this->world->admins['trialStarter']->fresh());

    expect(AutomationRuleResource::canAccess())->toBeFalse();
    $this->get('/admin/alpha-trial/automation-rules')->assertForbidden();
    Livewire::test(ListAutomationRules::class)->assertForbidden();

    $this->get('/admin/alpha-trial/candidates')->assertOk();
});

test('external job boards need the plan; the tenant\'s own careers site never does', function (): void {
    app(EntitlementOverrideService::class)->set($this->world->tenant('growth'), Entitlement::DistributionJobBoards, false, 'Boards paused');
    $posting = $this->world->in('growth', fn () => JobPosting::factory()->create(['requisition_id' => RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open])->id]));
    Queue::fake();

    expect(fn () => $this->world->in('growth', fn () => app(JobDistributionService::class)->publish($posting, ['naukri'])))->toThrow(EntitlementDenied::class, 'Job board distribution')
        ->and(fn () => $this->world->in('growth', fn () => app(JobDistributionService::class)->publish($posting, [JobDistributionService::CAREER_SITE, 'linkedin'])))->toThrow(EntitlementDenied::class)
        ->and($this->world->in('growth', fn () => app(JobDistributionService::class)->publish($posting->fresh(), [JobDistributionService::CAREER_SITE]))->count())->toBe(1);
});

test('data exports: no export record, no report download, and the export buttons are hidden', function (): void {
    app(EntitlementOverrideService::class)->set($this->world->tenant('growth'), Entitlement::ExportsData, false, 'Exports off');
    $admin = $this->world->admins['growth']->fresh();

    expect(fn () => $this->world->in('growth', fn () => Export::query()->create(['exporter' => 'x', 'file_disk' => 'local', 'total_rows' => 0, 'user_id' => $admin->id])))->toThrow(EntitlementDenied::class)
        ->and(fn () => $this->world->in('growth', fn () => app(ReportExportService::class)->streamCsv('x.csv', ['a'], [])))->toThrow(EntitlementDenied::class);

    $this->actInTenant($this->world->tenant('growth'));
    $this->actingAs($admin);

    Livewire::test(ListCandidates::class)->assertActionHidden(TestAction::make('export')->table());
    $this->get('/admin/bravo-growth/recruitment-reports')->assertOk()->assertDontSee('wire:click="exportFunnel"', false);
});

test('the plan never grants what the person\'s role does not', function (): void {
    $this->actInTenant($this->world->tenant('enterprise'));
    $employee = $this->world->in('enterprise', fn () => User::factory()->create()->assignRole('employee'));
    $this->actingAs($employee->fresh());

    expect(AiCopilot::canAccess())->toBeFalse()
        ->and(AutomationRuleResource::canAccess())->toBeFalse();
});
