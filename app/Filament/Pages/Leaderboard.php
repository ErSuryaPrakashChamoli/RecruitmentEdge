<?php

namespace App\Filament\Pages;

use App\Enums\MetricAccountability;
use App\Enums\TargetMetric;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterPerformanceSnapshot;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\PerformanceEngine;
use App\Services\RecruiterDailyMetricsService;
use App\Services\TargetResolutionService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Ranks recruiters by their current-month composite performance score (Section 22). Score comes
 * from the current-month RecruiterPerformanceSnapshot (a left join, so it sorts at the database
 * level); when a recruiter has no snapshot yet the composite is computed live via
 * PerformanceEngine instead of showing blank. Per-metric "actual / target" columns are always live
 * (RecruiterDailyMetricsService + TargetResolutionService) since a leaderboard should reflect
 * today's numbers, not last night's snapshot.
 */
class Leaderboard extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    private const LIVE_METRICS = [
        TargetMetric::ProfilesSourced,
        TargetMetric::Calls,
        TargetMetric::ConnectedCalls,
        TargetMetric::Screening,
        TargetMetric::Shortlisted,
        TargetMetric::Interviews,
        TargetMetric::Selections,
        TargetMetric::Offers,
        TargetMetric::Joining,
    ];

    protected string $view = 'filament.pages.leaderboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    /**
     * Per-request cache of live actual/target figures, keyed by employee id then metric value.
     *
     * @var array<int, array<string, array{actual: int, target: int|null}>>
     */
    protected array $liveMetricCache = [];

    /**
     * Per-request cache of live PerformanceEngine results for recruiters without a snapshot.
     *
     * @var array<int, array{score: float|null, breakdown: array<int, array{metric: string, weight: float, target: int|null, actual: int, achievement: float|null}>}>
     */
    protected array $liveResultCache = [];

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('performance.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array{total: int, scored: int, average: float|null, topName: string|null, topScore: float|null}
     */
    public function getSummary(): array
    {
        $rows = $this->baseQuery()
            ->get()
            ->map(fn (Employee $employee): array => ['employee' => $employee, 'score' => $this->scoreFor($employee)]);

        $scored = $rows->filter(fn (array $row): bool => $row['score'] !== null);
        $top = $scored->sortByDesc('score')->first();

        return [
            'total' => $rows->count(),
            'scored' => $scored->count(),
            'average' => $scored->isEmpty() ? null : round($scored->avg('score'), 1),
            'topName' => $top !== null ? $top['employee']->fullName() : null,
            'topScore' => $top !== null ? round($top['score'], 1) : null,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->columns([
                TextColumn::make('full_name')
                    ->label('Recruiter')
                    ->state(fn (Employee $record) => $record->fullName())
                    ->searchable(['first_name', 'last_name']),
                ...array_map(fn (TargetMetric $metric): TextColumn => $this->metricColumn($metric), self::LIVE_METRICS),
                ...array_map(
                    fn (MetricAccountability $accountability): TextColumn => TextColumn::make("{$accountability->value}_achievement")
                        ->label($accountability->label())
                        ->tooltip($accountability->description())
                        ->state(fn (Employee $record): ?float => PerformanceEngine::summarizeByAccountability($this->breakdownFor($record))[$accountability->value]['average_achievement'])
                        ->formatStateUsing(fn (?float $state): string => number_format((float) $state, 1).'%')
                        ->placeholder('—'),
                    MetricAccountability::cases(),
                ),
                TextColumn::make('achievement')
                    ->label('Achievement')
                    ->state(fn (Employee $record) => $this->unweightedAchievement($record))
                    ->formatStateUsing(fn (?float $state) => $state !== null ? number_format($state, 1).'%' : '—'),
                TextColumn::make('score')
                    ->label('Score')
                    ->sortable()
                    ->badge()
                    ->state(fn (Employee $record): ?float => $this->scoreFor($record))
                    ->formatStateUsing(fn (?float $state): string => number_format((float) $state, 1))
                    ->description(fn (Employee $record): ?string => $record->snapshot_id === null && $this->scoreFor($record) !== null ? 'Live' : null)
                    ->placeholder('—'),
            ])
            ->defaultSort('score', 'desc')
            ->paginated([10, 25, 50]);
    }

    private function metricColumn(TargetMetric $metric): TextColumn
    {
        return TextColumn::make($metric->value)
            ->label(match ($metric) {
                TargetMetric::ProfilesSourced => 'Profiles',
                TargetMetric::ConnectedCalls => 'Connected',
                TargetMetric::Selections => 'Selected',
                TargetMetric::Joining => 'Joined',
                default => $metric->label(),
            })
            ->state(function (Employee $record) use ($metric): string {
                ['actual' => $actual, 'target' => $target] = $this->liveMetricsFor($record)[$metric->value];

                return $target !== null ? "{$actual} / {$target}" : (string) $actual;
            })
            ->color(function (Employee $record) use ($metric): ?string {
                ['actual' => $actual, 'target' => $target] = $this->liveMetricsFor($record)[$metric->value];

                return match (true) {
                    $target === null || $target === 0 => null,
                    $actual >= $target => 'success',
                    default => 'warning',
                };
            });
    }

    /**
     * @return array<string, array{actual: int, target: int|null}>
     */
    private function liveMetricsFor(Employee $record): array
    {
        return $this->liveMetricCache[$record->id] ??= collect(self::LIVE_METRICS)
            ->mapWithKeys(fn (TargetMetric $metric): array => [$metric->value => [
                'actual' => app(RecruiterDailyMetricsService::class)->actualFor($record, $metric, now()->startOfMonth(), now()->endOfMonth()),
                'target' => app(TargetResolutionService::class)->resolveForRange($record, $metric, now()->startOfMonth(), now()->endOfMonth()),
            ]])
            ->all();
    }

    /**
     * @return array{score: float|null, breakdown: array<int, array{metric: string, weight: float, target: int|null, actual: int, achievement: float|null}>}
     */
    private function liveResultFor(Employee $record): array
    {
        return $this->liveResultCache[$record->id] ??= app(PerformanceEngine::class)
            ->computeFor($record, now()->startOfMonth(), now()->endOfMonth());
    }

    private function scoreFor(Employee $record): ?float
    {
        if ($record->snapshot_id !== null) {
            return $record->score !== null ? (float) $record->score : null;
        }

        return $this->liveResultFor($record)['score'];
    }

    /**
     * @return array<int, array{metric: string, weight?: float, target: int|null, actual: int, achievement: float|null}>
     */
    private function breakdownFor(Employee $record): array
    {
        if ($record->snapshot_id !== null) {
            return json_decode((string) ($record->breakdown ?? ''), true) ?? [];
        }

        return $this->liveResultFor($record)['breakdown'];
    }

    private function baseQuery(): Builder
    {
        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();

        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        $recruiterIds = CandidateApplication::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('recruiter_id', $visibleIds))
            ->distinct()
            ->pluck('recruiter_id');

        return Employee::query()
            ->whereIn('id', $recruiterIds)
            ->leftJoinSub(
                RecruiterPerformanceSnapshot::query()
                    ->whereDate('period_start', $periodStart)
                    ->whereDate('period_end', $periodEnd)
                    ->select('id as snapshot_id', 'employee_id', 'score', 'breakdown'),
                'current_snapshot',
                'current_snapshot.employee_id',
                '=',
                'employees.id',
            )
            ->select('employees.*', 'current_snapshot.snapshot_id', 'current_snapshot.score', 'current_snapshot.breakdown');
    }

    private function unweightedAchievement(Employee $record): ?float
    {
        $achievements = collect($this->breakdownFor($record))->pluck('achievement')->filter(fn ($v) => $v !== null);

        return $achievements->isEmpty() ? null : round($achievements->avg(), 1);
    }
}
