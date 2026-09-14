<?php

namespace App\Filament\Pages;

use App\Enums\CandidateStage;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\CandidateSource;
use App\Models\Department;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\CostPerHireService;
use App\Services\Export\ReportExportService;
use App\Services\RecruitmentAnalyticsService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * A single consolidated report combining Sections 32 (funnel), 33 (source analytics), 36 (vacancy
 * ageing), 34 (cost per hire), and 35 (time to hire) — all read through RecruitmentAnalyticsService
 * and CostPerHireService, hierarchy-scoped to the viewer. The requisition/department/source filters
 * apply to Cost per Hire only (the other report services don't take those dimensions), and are
 * labelled as such so no control silently does nothing.
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
                Grid::make(3)->schema([
                    Select::make('requisition_id')
                        ->label('Cost per Hire: Requisition')
                        ->options(fn () => RecruitmentRequisitionResource::getEloquentQuery()->orderBy('code')->pluck('code', 'id'))
                        ->searchable()
                        ->live(),
                    Select::make('department_id')
                        ->label('Cost per Hire: Department')
                        ->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->live(),
                    Select::make('source_id')
                        ->label('Cost per Hire: Source')
                        ->options(fn () => CandidateSource::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->live(),
                ]),
            ])
            ->statePath('data');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(): array
    {
        $state = $this->form->getState();

        return [
            Carbon::parse($state['start'])->startOfDay(),
            Carbon::parse($state['end'])->endOfDay(),
        ];
    }

    private function viewer(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    /** @return Collection<int, array{stage: CandidateStage, count: int, conversion_from_sourced: float|null}> */
    public function getFunnel(): Collection
    {
        [$start, $end] = $this->period();

        return app(RecruitmentAnalyticsService::class)->funnel($start, $end, $this->viewer());
    }

    /** @return Collection<int, array{source: CandidateSource, spend: float, sourced: int, connected: int, interested: int, interviewed: int, selected: int, offers: int, joined: int, conversion_percent: float|null, cost_per_interview: float|null, cost_per_selection: float|null, cost_per_join: float|null}> */
    public function getSourceAnalytics(): Collection
    {
        [$start, $end] = $this->period();

        return app(RecruitmentAnalyticsService::class)->sourceAnalytics($start, $end, $this->viewer());
    }

    /** @return Collection<int, array{requisition: RecruitmentRequisition, ageing_days: int, is_overdue: bool, priority: string|null}> */
    public function getVacancyAgeing(): Collection
    {
        return app(RecruitmentAnalyticsService::class)->vacancyAgeing($this->viewer());
    }

    public function getAverageTimeToHire(): ?float
    {
        [$start, $end] = $this->period();

        return app(RecruitmentAnalyticsService::class)->averageTimeToHireDays($start, $end, $this->viewer());
    }

    public function getCostPerHire(): ?float
    {
        [$start, $end] = $this->period();
        $state = $this->form->getState();

        return app(CostPerHireService::class)->costPerHire(
            $start,
            $end,
            filled($state['requisition_id'] ?? null) ? (int) $state['requisition_id'] : null,
            filled($state['department_id'] ?? null) ? (int) $state['department_id'] : null,
            filled($state['source_id'] ?? null) ? (int) $state['source_id'] : null,
            $this->viewer(),
        );
    }

    /**
     * Display label for a vacancy-ageing row's priority, whether it arrives as an enum or a plain
     * string key.
     */
    public function priorityLabel(mixed $priority): ?string
    {
        return match (true) {
            $priority === null || $priority === '' => null,
            $priority instanceof BackedEnum && method_exists($priority, 'label') => $priority->label(),
            $priority instanceof BackedEnum => Str::headline((string) $priority->value),
            default => Str::headline((string) $priority),
        };
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
            $this->priorityLabel($row['priority'] ?? null) ?? '',
            $row['ageing_days'],
            $row['is_overdue'] ? 'Yes' : 'No',
        ]);

        return app(ReportExportService::class)->streamCsv(
            'vacancy-ageing.csv',
            ['Requisition', 'Designation', 'Priority', 'Ageing (days)', 'Overdue'],
            $rows,
        );
    }
}
