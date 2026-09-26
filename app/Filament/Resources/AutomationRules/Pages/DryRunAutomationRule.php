<?php

namespace App\Filament\Resources\AutomationRules\Pages;

use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationEventRegistry;
use App\Services\HierarchyService;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Dry run / test mode (Phase 6): pick a real record the rule could apply to and see — without
 * sending, notifying or changing anything — whether it is in scope, how each condition evaluates,
 * when it would run, what each action would do and who each escalation step would reach. Uses the
 * rule's current (even unsaved-to-active) configuration.
 */
class DryRunAutomationRule extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AutomationRuleResource::class;

    protected string $view = 'filament.resources.automation-rules.dry-run';

    public ?string $subjectId = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $report = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('view', $this->record), 403);
    }

    public function getTitle(): string
    {
        return "Dry run: {$this->getRule()->name}";
    }

    public function getRule(): AutomationRule
    {
        /** @var AutomationRule $rule */
        $rule = $this->getRecord();

        return $rule;
    }

    /**
     * Recent records of the trigger's type that the viewer may see.
     *
     * @return array<int|string, string>
     */
    public function subjectOptions(): array
    {
        $definition = app(AutomationEventRegistry::class)->find($this->getRule()->trigger);

        if ($definition === null) {
            return [];
        }

        return $this->subjectQuery($definition->subjectClass)
            ->latest((new $definition->subjectClass)->getQualifiedKeyName())
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Model $subject) => [$subject->getKey() => $this->describe($subject)])
            ->all();
    }

    public function run(): void
    {
        $definition = app(AutomationEventRegistry::class)->find($this->getRule()->trigger);
        $subject = $definition !== null && filled($this->subjectId)
            ? $this->subjectQuery($definition->subjectClass)->find($this->subjectId)
            : null;

        if ($subject === null) {
            $this->addError('subjectId', 'Choose a record to test against.');

            return;
        }

        $this->report = app(AutomationEngine::class)->dryRun($this->getRule(), $subject, auth()->user());
    }

    /**
     * @param  class-string<Model>  $class
     * @return Builder<Model>
     */
    private function subjectQuery(string $class): Builder
    {
        /** @var User $user */
        $user = auth()->user();
        $visible = app(HierarchyService::class)->visibleEmployeeIdsFor($user);
        $query = $class::query();

        if ($visible === null) {
            return $query;
        }

        return match (true) {
            $query->getModel() instanceof CandidateApplication => $query->whereIn('recruiter_id', $visible),
            $query->getModel() instanceof RecruitmentRequisition => $query->where(fn (Builder $q) => $q->whereIn('manager_id', $visible)->orWhereHas('recruiters', fn (Builder $r) => $r->whereIn('employees.id', $visible))),
            method_exists($query->getModel(), 'candidateApplication') => $query->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visible)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function describe(Model $subject): string
    {
        $application = $subject instanceof CandidateApplication ? $subject : ($subject->candidateApplication ?? null);
        $name = $application?->candidate?->full_name ?? $subject->candidate?->full_name ?? $subject->code ?? '';
        $when = $subject->scheduled_at ?? $subject->expected_doj ?? $subject->offer_date ?? null;

        return trim(class_basename($subject)." #{$subject->getKey()} — {$name}".($when !== null ? ' · '.$when->toDayDateTimeString() : ''));
    }
}
