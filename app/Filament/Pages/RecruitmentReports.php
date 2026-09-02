<?php

namespace App\Filament\Pages;

use App\Enums\CandidateStage;
use App\Models\CandidateSource;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\CostPerHireService;
use App\Services\Export\ReportExportService;
use App\Services\RecruitmentAnalyticsService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * A single consolidated report combining Sections 32 (funnel), 33 (source analytics), 36 (vacancy
 * ageing), 34 (cost per hire), and 35 (time to hire) — all read through RecruitmentAnalyticsService
 * and CostPerHireService, hierarchy-scoped to the viewer.
 */
class RecruitmentReports extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.recruitment-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Recruitment Reports';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('performance.view');
    }

    public function mount(): void
    {
        $this->form->fill([
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    DatePicker::make('start')->label('From')->required()->live(),
                    DatePicker::make('end')->label('To')->required()->live(),
                ]),
            ])
            ->statePath('data');
    }

    private function period(): array
    {
        $state = $this->form->getState();

        return [
            Carbon::parse($state['start'])->startOfDay(),
            Carbon::parse($state['end'])->endOfDay(),
        ];
    }

    /** @return Collection<int, array{stage: CandidateStage, count: int, conversion_from_sourced: float|null}> */
    public function getFunnel(): Collection
    {
        [$start, $end] = $this->period();

        /** @var User $user */
        $user = Filament::auth()->user();

        return app(RecruitmentAnalyticsService::class)->funnel($start, $end, $user);
    }

    /** @return Collection<int, array{source: CandidateSource, spend: float, sourced: int, connected: int, interested: int, interviewed: int, selected: int, offers: int, joined: int, conversion_percent: float|null, cost_per_interview: float|null, cost_per_selection: float|null, cost_per_join: float|null}> */
    public function getSourceAnalytics(): Collection
    {
        [$start, $end] = $this->period();

        /** @var User $user */
        $user = Filament::auth()->user();

        return app(RecruitmentAnalyticsService::class)->sourceAnalytics($start, $end, $user);
    }

    /** @return Collection<int, array{requisition: RecruitmentRequisition, ageing_days: int, is_overdue: bool}> */
    public function getVacancyAgeing(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return app(RecruitmentAnalyticsService::class)->vacancyAgeing($user);
    }

    public function getAverageTimeToHire(): ?float
    {
        [$start, $end] = $this->period();

        /** @var User $user */
        $user = Filament::auth()->user();

        return app(RecruitmentAnalyticsService::class)->averageTimeToHireDays($start, $end, $user);
    }

    public function getCostPerHire(): ?float
    {
        [$start, $end] = $this->period();

        return app(CostPerHireService::class)->costPerHire($start, $end);
    }

    public function canExport(): bool
    {
        return (bool) Filament::auth()->user()?->can('reports.export');
    }

    public function exportFunnel(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);

        $rows = $this->getFunnel()->map(fn (array $row) => [
            $row['stage']->label(),
            $row['count'],
            $row['conversion_from_sourced'] !== null ? $row['conversion_from_sourced'].'%' : '',
        ]);

        return app(ReportExportService::class)->streamCsv(
            'recruitment-funnel.csv',
            ['Stage', 'Count', 'Conversion from Sourced'],
            $rows,
        );
    }

    public function exportSourceRoi(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);

        $rows = $this->getSourceAnalytics()->map(fn (array $row) => [
            $row['source']->name,
            $row['spend'],
            $row['sourced'],
            $row['connected'],
            $row['interested'],
            $row['interviewed'],
            $row['selected'],
            $row['offers'],
            $row['joined'],
            $row['conversion_percent'] !== null ? $row['conversion_percent'].'%' : '',
            $row['cost_per_interview'] ?? '',
            $row['cost_per_selection'] ?? '',
            $row['cost_per_join'] ?? '',
        ]);

        return app(ReportExportService::class)->streamCsv(
            'source-roi.csv',
            ['Source', 'Spend', 'Sourced', 'Connected', 'Interested', 'Interviewed', 'Selected', 'Offers', 'Joined', 'Conversion %', 'Cost per Interview', 'Cost per Selection', 'Cost per Join'],
            $rows,
        );
    }

    public function exportVacancyAgeing(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);

        $rows = $this->getVacancyAgeing()->map(fn (array $row) => [
            $row['requisition']->code,
            $row['requisition']->designation?->name,
            $row['ageing_days'],
            $row['is_overdue'] ? 'Yes' : 'No',
        ]);

        return app(ReportExportService::class)->streamCsv(
            'vacancy-ageing.csv',
            ['Requisition', 'Designation', 'Ageing (days)', 'Overdue'],
            $rows,
        );
    }
}
