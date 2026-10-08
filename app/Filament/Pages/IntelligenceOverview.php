<?php

namespace App\Filament\Pages;

use App\Enums\HealthStatus;
use App\Enums\RequisitionStatus;
use App\Enums\RiskSeverity;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\HiringHealthSnapshot;
use App\Models\HiringRisk;
use App\Models\RecruitmentRequisition;
use App\Models\RoleDnaProfile;
use App\Models\User;
use App\Services\AI\Gateway\AiGateway;
use App\Services\Intelligence\HiringHealthService;
use App\Services\Intelligence\HiringRiskRadar;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * EDGE Intelligence™ overview (Phase 7): Hiring Health across the viewer's open requisitions, the
 * highest-severity open risks and Role DNA status. Reads persisted intelligence only — no
 * computation or AI on render; "Refresh" is an explicit, bounded action.
 */
class IntelligenceOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'EDGE Intelligence';

    protected static ?string $navigationLabel = 'Intelligence Overview';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'EDGE Intelligence';

    protected string $view = 'filament.pages.intelligence-overview';

    /**
     * Worst first.
     *
     * @var array<string, int>
     */
    private const array STATUS_ORDER = ['critical' => 0, 'at_risk' => 1, 'watch' => 2, 'insufficient_data' => 3, 'healthy' => 4];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('intelligence.view') ?? false;
    }

    /**
     * @return Collection<int, RecruitmentRequisition>
     */
    public function requisitions(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        $requisitions = RecruitmentRequisition::query()
            ->visibleTo($user)
            ->where('status', RequisitionStatus::Open)
            ->with(['designation:id,name'])
            ->orderBy('code')
            ->limit(100)
            ->get();

        $health = HiringHealthSnapshot::query()->whereIn('requisition_id', $requisitions->pluck('id'))->where('is_current', true)->get()->keyBy('requisition_id');
        $risks = HiringRisk::query()->open()->whereIn('requisition_id', $requisitions->pluck('id'))->selectRaw('requisition_id, count(*) as total')->groupBy('requisition_id')->pluck('total', 'requisition_id');
        $dna = RoleDnaProfile::query()->whereIn('requisition_id', $requisitions->pluck('id'))->get()->keyBy('requisition_id');

        return $requisitions->map(function (RecruitmentRequisition $requisition) use ($health, $risks, $dna) {
            $requisition->setAttribute('intel_health', $health->get($requisition->id));
            $requisition->setAttribute('intel_risks', (int) ($risks[$requisition->id] ?? 0));
            $requisition->setAttribute('intel_dna', $dna->get($requisition->id));

            return $requisition;
        })->sortBy(fn (RecruitmentRequisition $r) => [self::STATUS_ORDER[$r->intel_health?->status?->value] ?? 9, $r->code])->values();
    }

    /**
     * @param  Collection<int, RecruitmentRequisition>  $requisitions
     * @return array<string, int>
     */
    public function distribution(Collection $requisitions): array
    {
        return collect(HealthStatus::cases())->mapWithKeys(fn (HealthStatus $status) => [$status->value => $requisitions->filter(fn ($r) => $r->intel_health?->status === $status)->count()])
            ->put('not_computed', $requisitions->filter(fn ($r) => $r->intel_health === null)->count())
            ->all();
    }

    /**
     * @return Collection<int, HiringRisk>
     */
    public function topRisks(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return HiringRisk::query()
            ->open()
            ->whereIn('severity', [RiskSeverity::Critical, RiskSeverity::High])
            ->whereIn('requisition_id', RecruitmentRequisition::query()->visibleTo($user)->select('id'))
            ->with('requisition:id,code')
            ->latest('last_seen_at')
            ->limit(10)
            ->get()
            ->sortBy(fn (HiringRisk $risk) => $risk->severity->rank())
            ->values();
    }

    public function isFresh(?HiringHealthSnapshot $snapshot): bool
    {
        return app(HiringHealthService::class)->isFresh($snapshot);
    }

    public function intelligenceUrl(RecruitmentRequisition $requisition): string
    {
        return RecruitmentRequisitionResource::getUrl('intelligence', ['record' => $requisition]);
    }

    public function aiConfigured(): bool
    {
        return app(AiGateway::class)->isConfigured();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh my requisitions')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $requisitions = $this->requisitions()->take(50);
                    $health = app(HiringHealthService::class);
                    $radar = app(HiringRiskRadar::class);
                    $opened = 0;

                    foreach ($requisitions as $requisition) {
                        $health->refresh($requisition, auth()->user());
                        $opened += $radar->scan($requisition)['opened'];
                    }

                    Notification::make()->title("{$requisitions->count()} requisition(s) refreshed")->body("{$opened} new risk(s) detected.")->success()->send();
                }),
        ];
    }
}
