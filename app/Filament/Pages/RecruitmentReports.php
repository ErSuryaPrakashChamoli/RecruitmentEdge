<?php

namespace App\Filament\Pages;

use App\Enums\CandidateStage;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\AuditLog;
use App\Models\CandidateSource;
use App\Models\Department;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\CostPerHireService;
use App\Services\Export\ReportExportService;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricService;
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
 * apply to Cost per Hire and Time to Hire only (the funnel and source tables don't take those
 * dimensions), and are labelled as such so no control silently does nothing.
 *
 * Phase 8.5: every figure is a governed metric (the same number the dashboard and Copilot show),
 * and each CSV names the metric and version it was computed with.
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
                        ->label('Cost & Time to Hire: Requisition')
                        ->options(fn () => RecruitmentRequisitionResource::getEloquentQuery()->orderBy('code')->pluck('code', 'id'))
                        ->searchable()
                        ->live(),
                    Select::make('department_id')
                        ->label('Cost & Time to Hire: Department')
                        ->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->live(),
                    Select::make('source_id')
                        ->label('Cost & Time to Hire: Source')
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

    /** @return Collection<int, array{source: CandidateSource|null, source_name: string, spend: float, sourced: int, connected: int, interested: int, interviewed: int, selected: int, offers: int, joined: int, conversion_percent: float|null, cost_per_interview: float|null, cost_per_selection: float|null, cost_per_join: float|null}> */
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

    public function getTimeToHire(): MetricResult
    {
        [$start, $end] = $this->period();

        return app(RecruitmentAnalyticsService::class)->metric('hiring.time_to_hire', $start, $end, $this->viewer(), $this->dimensionFilters());
    }

    public function getCostPerHire(): MetricResult
    {
        [$start, $end] = $this->period();
        $filters = $this->dimensionFilters();

        return app(CostPerHireService::class)->result($start, $end, $filters['requisition_id'], $filters['department_id'], $filters['source_id'], $this->viewer());
    }

    /**
     * @return array{requisition_id: int|null, department_id: int|null, source_id: int|null}
     */
    private function dimensionFilters(): array
    {
        $state = $this->form->getState();

        return [
            'requisition_id' => filled($state['requisition_id'] ?? null) ? (int) $state['requisition_id'] : null,
            'department_id' => filled($state['department_id'] ?? null) ? (int) $state['department_id'] : null,
            'source_id' => filled($state['source_id'] ?? null) ? (int) $state['source_id'] : null,
        ];
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
            $this->metricLabel('pipeline.funnel'),
        ]);

        $this->auditReportExport('recruitment-funnel', $rows->count());

        return app(ReportExportService::class)->streamCsv(
            'recruitment-funnel.csv',
            ['Stage', 'Reached (cohort)', '% of applications in period', 'Metric'],
            $rows,
        );
    }

    public function exportSourceRoi(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);

        $rows = $this->getSourceAnalytics()->map(fn (array $row) => [
            $row['source_name'],
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
            'source-roi (Phase 8.5)',
        ]);

        $this->auditReportExport('source-roi', $rows->count());

        return app(ReportExportService::class)->streamCsv(
            'source-roi.csv',
            ['Source', 'Spend', 'Applications', 'Connected', 'Interested', 'Interviewed', 'Selected', 'Offers', 'Joined', 'Conversion %', 'Cost per Interview', 'Cost per Selection', 'Cost per Join', 'Definition'],
            $rows,
        );
    }

    /**
     * Phase 8.8 (SEC-88-13): who exported which report, and how many rows.
     */
    private function auditReportExport(string $report, int $rows): void
    {
        $user = Filament::auth()->user();

        if ($user !== null) {
            AuditLog::record($user, 'report_exported', null, ['report' => $report, 'rows' => $rows]);
        }
    }

    private function metricLabel(string $key): string
    {
        return $key.' v'.app(MetricService::class)->spec($key)->version;
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
            $this->metricLabel('requisition.ageing'),
        ]);

        $this->auditReportExport('vacancy-ageing', $rows->count());

        return app(ReportExportService::class)->streamCsv(
            'vacancy-ageing.csv',
            ['Requisition', 'Designation', 'Priority', 'Ageing (days)', 'Overdue', 'Metric'],
            $rows,
        );
    }
}
