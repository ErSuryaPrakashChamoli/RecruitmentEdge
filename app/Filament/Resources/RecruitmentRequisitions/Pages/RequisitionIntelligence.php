<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Pages;

use App\Enums\ApplicationStatus;
use App\Enums\Entitlement;
use App\Enums\IntelligenceAiStatus;
use App\Enums\RequirementLevel;
use App\Enums\RoleDnaCategory;
use App\Enums\TalentPoolStatus;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Concerns\ShowsIntelligenceEvidence;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\CandidateApplication;
use App\Models\HiringHealthSnapshot;
use App\Models\HiringMemoryRecord;
use App\Models\HiringRisk;
use App\Models\RecruitmentRequisition;
use App\Models\RediscoveryResult;
use App\Models\RediscoveryRun;
use App\Models\RoleDnaProfile;
use App\Models\RoleDnaVersion;
use App\Models\TalentPool;
use App\Models\TalentSignalSnapshot;
use App\Services\AI\Gateway\AiGateway;
use App\Services\Entitlements\EntitlementService;
use App\Services\Intelligence\HiringHealthService;
use App\Services\Intelligence\HiringRiskRadar;
use App\Services\Intelligence\IntelligenceAiService;
use App\Services\Intelligence\RoleDnaService;
use App\Services\Intelligence\TalentRediscoveryService;
use App\Services\Intelligence\TalentSignalService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

/**
 * EDGE Intelligence™ for one requisition (Phase 7): Hiring Health, open risks, Role DNA, candidate
 * Talent Signals, Talent Rediscovery and Hiring Memory — each value with its "Why?" evidence.
 *
 * Rendering never computes intelligence or calls AI: it shows what is persisted, with its age, and
 * every refresh or AI request is an explicit, permission-gated action.
 */
class RequisitionIntelligence extends Page
{
    use GuardsDomainExceptions, InteractsWithRecord, ShowsIntelligenceEvidence;

    protected static string $resource = RecruitmentRequisitionResource::class;

    protected string $view = 'filament.resources.recruitment-requisitions.intelligence';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('intelligence.view') && auth()->user()->can('view', $this->record), 403);
    }

    public function getTitle(): string
    {
        return "EDGE Intelligence — {$this->requisition()->code}";
    }

    public function requisition(): RecruitmentRequisition
    {
        /** @var RecruitmentRequisition $requisition */
        $requisition = $this->getRecord();

        return $requisition;
    }

    public function health(): ?HiringHealthSnapshot
    {
        return app(HiringHealthService::class)->currentFor($this->requisition());
    }

    public function healthIsFresh(): bool
    {
        return app(HiringHealthService::class)->isFresh($this->health());
    }

    /**
     * @return Collection<int, HiringRisk>
     */
    public function risks(): Collection
    {
        return HiringRisk::query()->open()->where('requisition_id', $this->requisition()->id)->withCount('evidence')
            ->get()->sortBy(fn (HiringRisk $risk) => $risk->severity->rank())->values();
    }

    public function profile(): ?RoleDnaProfile
    {
        return RoleDnaProfile::query()->where('requisition_id', $this->requisition()->id)->with('currentVersion')->first();
    }

    public function dna(): ?RoleDnaVersion
    {
        return $this->profile()?->currentVersion;
    }

    /**
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function dnaByCategory(): Collection
    {
        return collect($this->dna()?->dna ?? [])
            ->filter(fn (array $a) => ($a['active'] ?? true) && $a['origin'] !== 'ai_suggestion')
            ->groupBy('category');
    }

    /**
     * @return Collection<int, TalentSignalSnapshot>
     */
    public function signals(): Collection
    {
        return TalentSignalSnapshot::query()
            ->where('requisition_id', $this->requisition()->id)
            ->where('is_current', true)
            ->whereHas('candidateApplication', fn ($q) => $q->where('status', ApplicationStatus::Active))
            ->with(['candidate:id,full_name', 'candidateApplication:id,application_code,current_stage', 'roleDnaVersion:id,version'])
            ->get()
            ->sortBy(fn (TalentSignalSnapshot $s) => [array_search($s->band->value, ['strong', 'moderate', 'weak', 'insufficient_evidence'], true), -($s->required_coverage_pct ?? 0)])
            ->values();
    }

    public function applicationsWithoutSignal(): int
    {
        $signalled = TalentSignalSnapshot::query()->where('requisition_id', $this->requisition()->id)->where('is_current', true)->pluck('candidate_id');

        return CandidateApplication::query()->where('requisition_id', $this->requisition()->id)->where('status', ApplicationStatus::Active)->whereNotIn('candidate_id', $signalled)->count();
    }

    public function latestRun(): ?RediscoveryRun
    {
        return RediscoveryRun::query()->where('requisition_id', $this->requisition()->id)->with(['results.candidate:id,full_name', 'runBy:id,name', 'roleDnaVersion:id,version'])->latest('id')->first();
    }

    /**
     * @return Collection<int, HiringMemoryRecord>
     */
    public function memory(): Collection
    {
        return HiringMemoryRecord::query()->where('requisition_id', $this->requisition()->id)->where('is_current', true)->latest('captured_at')->limit(10)->get();
    }

    public function designationMemoryCount(): int
    {
        return $this->requisition()->designation_id === null ? 0 : HiringMemoryRecord::query()->where('designation_id', $this->requisition()->designation_id)->where('is_current', true)->count();
    }

    public function aiConfigured(): bool
    {
        return app(AiGateway::class)->isConfigured();
    }

    public function userCan(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }

    public function refreshHealthAction(): Action
    {
        return Action::make('refreshHealth')
            ->label('Refresh health & risks')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->action(function (): void {
                app(HiringHealthService::class)->refresh($this->requisition(), auth()->user(), force: true);
                $counts = app(HiringRiskRadar::class)->scan($this->requisition());
                Notification::make()->title('Hiring Health refreshed')->body("Risks: {$counts['opened']} new, {$counts['refreshed']} still open, {$counts['resolved']} resolved.")->success()->send();
            });
    }

    public function buildRoleDnaAction(): Action
    {
        return Action::make('buildRoleDna')
            ->label(fn () => $this->dna() === null ? 'Build Role DNA' : 'Rebuild from requisition')
            ->icon('heroicon-o-finger-print')
            ->color(fn () => $this->dna() === null ? 'primary' : 'gray')
            ->visible(fn () => $this->userCan('intelligence.role-dna.manage'))
            ->action(function (): void {
                $version = app(RoleDnaService::class)->rebuild($this->requisition(), auth()->user());
                Notification::make()->title("Role DNA v{$version->version}")->success()->send();
            });
    }

    public function addAttributeAction(): Action
    {
        return Action::make('addAttribute')
            ->label('Add attribute')
            ->icon('heroicon-o-plus')
            ->color('gray')
            ->visible(fn () => $this->userCan('intelligence.role-dna.manage') && $this->dna() !== null)
            ->schema([
                Select::make('category')->options(collect(RoleDnaCategory::cases())->reject(fn ($c) => in_array($c, [RoleDnaCategory::HistoricalPattern, RoleDnaCategory::Identity], true))->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())->required(),
                TextInput::make('label')->label('Name')->required()->maxLength(80),
                TextInput::make('value')->label('Detail (optional)')->maxLength(200),
                Select::make('level')->options(RequirementLevel::options())->default(RequirementLevel::Preferred->value)->required(),
            ])
            ->action(function (array $data): void {
                self::guarded('Could not add the attribute', fn () => app(RoleDnaService::class)->addAttribute($this->requisition(), $data, auth()->user()));
                Notification::make()->title('Attribute added (new Role DNA version)')->success()->send();
            });
    }

    public function confirmAttributeAction(): Action
    {
        return Action::make('confirmAttribute')
            ->label('Confirm')
            ->icon('heroicon-o-check')
            ->color('success')
            ->size('sm')
            ->visible(fn () => $this->userCan('intelligence.role-dna.manage'))
            ->action(function (array $arguments): void {
                self::guarded('Could not confirm', fn () => app(RoleDnaService::class)->confirmAttribute($this->requisition(), (string) $arguments['key'], auth()->user()));
                Notification::make()->title('Suggestion confirmed')->success()->send();
            });
    }

    public function rejectAttributeAction(): Action
    {
        return Action::make('rejectAttribute')
            ->label('Reject')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->size('sm')
            ->visible(fn () => $this->userCan('intelligence.role-dna.manage'))
            ->schema([Textarea::make('reason')->required()->rows(2)])
            ->action(function (array $arguments, array $data): void {
                self::guarded('Could not reject', fn () => app(RoleDnaService::class)->rejectAttribute($this->requisition(), (string) $arguments['key'], auth()->user(), $data['reason']));
                Notification::make()->title('Attribute rejected')->success()->send();
            });
    }

    public function confirmRoleDnaAction(): Action
    {
        return Action::make('confirmRoleDna')
            ->label('Confirm Role DNA')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Confirms this version as the reviewed definition of the role. Any later change returns it to draft.')
            ->visible(fn () => $this->userCan('intelligence.role-dna.manage') && $this->dna() !== null && $this->profile()?->status->value === 'draft')
            ->action(function (): void {
                self::guarded('Could not confirm the Role DNA', fn () => app(RoleDnaService::class)->confirmProfile($this->requisition(), auth()->user()));
                Notification::make()->title('Role DNA confirmed')->success()->send();
            });
    }

    public function requestAiSuggestionsAction(): Action
    {
        return Action::make('requestAiSuggestions')
            ->label('Suggest with AI')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Sends role-level information only (designation, skills, experience, qualification, public job description — no candidate data) to the configured AI provider. Suggestions are added unconfirmed; they affect nothing until a person confirms each one.')
            ->visible(fn () => $this->userCan('intelligence.role-dna.manage') && $this->userCan('ai.query') && $this->dna() !== null && app(EntitlementService::class)->allows(Entitlement::AiAssistant))
            ->disabled(fn () => $this->profile()?->ai_status === IntelligenceAiStatus::Processing)
            ->action(function (): void {
                $status = app(IntelligenceAiService::class)->requestRoleDnaSuggestions($this->profile(), auth()->user());
                [$title, $body, $color] = match ($status) {
                    IntelligenceAiStatus::Unavailable => ['AI is not configured', 'Everything else keeps working without AI.', 'warning'],
                    IntelligenceAiStatus::Failed => ['The AI service is unavailable right now', 'Nothing was changed. Try again later — everything else keeps working.', 'warning'],
                    IntelligenceAiStatus::Available => ['AI suggestions received', 'They are listed below as unconfirmed suggestions.', 'success'],
                    default => ['AI suggestions requested', 'They will appear here as unconfirmed suggestions when ready.', 'success'],
                };

                Notification::make()->title($title)->body($body)->color($color)->send();
            });
    }

    public function refreshSignalsAction(): Action
    {
        return Action::make('refreshSignals')
            ->label('Refresh Talent Signals')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->action(function (): void {
                $count = app(TalentSignalService::class)->refreshForRequisition($this->requisition(), 200, auth()->user());
                Notification::make()->title("{$count} Talent Signal(s) refreshed")->body('Only signals whose candidate, application or Role DNA changed are recomputed.')->success()->send();
            });
    }

    public function runRediscoveryAction(): Action
    {
        return Action::make('runRediscovery')
            ->label('Rediscover talent')
            ->icon('heroicon-o-magnifying-glass')
            ->visible(fn () => $this->userCan('intelligence.rediscover'))
            ->action(function (): void {
                $run = app(TalentRediscoveryService::class)->run($this->requisition(), auth()->user());
                Notification::make()->title("{$run->results_count} candidate(s) rediscovered")->body("{$run->candidates_scanned} known candidate(s) compared with Role DNA v{$run->roleDnaVersion?->version}.")->success()->send();
            });
    }

    public function addToRequisitionAction(): Action
    {
        return Action::make('addToRequisition')
            ->label('Add to requisition')
            ->icon('heroicon-o-user-plus')
            ->size('sm')
            ->requiresConfirmation()
            ->modalDescription('Creates a new application at the Sourced stage. Nothing else about the candidate changes.')
            ->visible(fn () => $this->userCan('intelligence.rediscover') && $this->userCan('candidates.create'))
            ->action(function (array $arguments): void {
                $result = $this->result($arguments);
                $application = self::guarded('Could not add the candidate', fn () => app(TalentRediscoveryService::class)->addToRequisition($result, auth()->user()));
                Notification::make()->title("Added as {$application->application_code}")->success()->send();
            });
    }

    public function addToPoolAction(): Action
    {
        return Action::make('addToPool')
            ->label('Add to pool')
            ->icon('heroicon-o-rectangle-stack')
            ->size('sm')
            ->color('gray')
            ->visible(fn () => $this->userCan('intelligence.rediscover') && $this->userCan('talent-pools.viewAny'))
            ->schema([
                Select::make('pool_id')->label('Talent pool')->required()->searchable()
                    ->options(fn () => TalentPool::query()->visibleTo(auth()->user())->where('status', TalentPoolStatus::Active)->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->action(function (array $arguments, array $data): void {
                $pool = TalentPool::query()->visibleTo(auth()->user())->findOrFail($data['pool_id']);
                self::guarded('Could not add to the pool', fn () => app(TalentRediscoveryService::class)->addToPool($this->result($arguments), $pool, auth()->user()));
                Notification::make()->title("Added to {$pool->name}")->success()->send();
            });
    }

    public function dismissResultAction(): Action
    {
        return Action::make('dismissResult')
            ->label('Dismiss')
            ->icon('heroicon-o-x-mark')
            ->size('sm')
            ->color('gray')
            ->visible(fn () => $this->userCan('intelligence.rediscover'))
            ->schema([Textarea::make('note')->label('Why not a fit?')->required()->rows(2)])
            ->action(function (array $arguments, array $data): void {
                self::guarded('Could not dismiss', fn () => app(TalentRediscoveryService::class)->dismiss($this->result($arguments), auth()->user(), $data['note']));
                Notification::make()->title('Suggestion dismissed')->success()->send();
            });
    }

    public function acknowledgeRiskAction(): Action
    {
        return Action::make('acknowledgeRisk')
            ->label('Acknowledge')
            ->size('sm')
            ->color('gray')
            ->visible(fn () => $this->userCan('intelligence.risks.manage'))
            ->action(function (array $arguments): void {
                self::guarded('Could not acknowledge', fn () => app(HiringRiskRadar::class)->acknowledge($this->risk($arguments), auth()->user()));
                Notification::make()->title('Risk acknowledged')->success()->send();
            });
    }

    public function dismissRiskAction(): Action
    {
        return Action::make('dismissRisk')
            ->label('Dismiss')
            ->size('sm')
            ->color('gray')
            ->visible(fn () => $this->userCan('intelligence.risks.manage'))
            ->schema([Textarea::make('reason')->required()->rows(2)->helperText('The same risk will not be raised again for 7 days.')])
            ->action(function (array $arguments, array $data): void {
                self::guarded('Could not dismiss', fn () => app(HiringRiskRadar::class)->dismiss($this->risk($arguments), auth()->user(), $data['reason']));
                Notification::make()->title('Risk dismissed')->success()->send();
            });
    }

    private function result(array $arguments): RediscoveryResult
    {
        return RediscoveryResult::query()
            ->whereHas('run', fn ($q) => $q->where('requisition_id', $this->requisition()->id))
            ->findOrFail($arguments['result'] ?? null);
    }

    private function risk(array $arguments): HiringRisk
    {
        return HiringRisk::query()->where('requisition_id', $this->requisition()->id)->findOrFail($arguments['risk'] ?? null);
    }
}
