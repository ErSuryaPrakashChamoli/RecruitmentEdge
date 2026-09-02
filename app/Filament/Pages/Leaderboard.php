<?php

namespace App\Filament\Pages;

use App\Enums\TargetMetric;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterPerformanceSnapshot;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\RecruiterDailyMetricsService;
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
 * from the latest RecruiterPerformanceSnapshot (a left join, so it sorts at the database level);
 * the per-metric activity columns are computed live via RecruiterDailyMetricsService since a
 * leaderboard should reflect today's numbers, not last night's snapshot.
 */
class Leaderboard extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected string $view = 'filament.pages.leaderboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

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
        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();

        $rows = $this->baseQuery($periodStart, $periodEnd)->get();
        $scored = $rows->filter(fn (Employee $employee) => $employee->score !== null);
        $top = $scored->sortByDesc(fn (Employee $employee) => (float) $employee->score)->first();

        return [
            'total' => $rows->count(),
            'scored' => $scored->count(),
            'average' => $scored->isEmpty() ? null : round($scored->avg(fn (Employee $employee) => (float) $employee->score), 1),
            'topName' => $top?->fullName(),
            'topScore' => $top !== null ? round((float) $top->score, 1) : null,
        ];
    }

    public function table(Table $table): Table
    {
        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();

        return $table
            ->query($this->baseQuery($periodStart, $periodEnd))
            ->columns([
                TextColumn::make('full_name')
                    ->label('Recruiter')
                    ->state(fn (Employee $record) => $record->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('profiles')
                    ->label('Profiles')
                    ->state(fn (Employee $record) => app(RecruiterDailyMetricsService::class)
                        ->actualFor($record, TargetMetric::ProfilesSourced, now()->startOfMonth(), now()->endOfMonth())),
                TextColumn::make('interviews')
                    ->label('Interviews')
                    ->state(fn (Employee $record) => app(RecruiterDailyMetricsService::class)
                        ->actualFor($record, TargetMetric::Interviews, now()->startOfMonth(), now()->endOfMonth())),
                TextColumn::make('selected')
                    ->label('Selected')
                    ->state(fn (Employee $record) => app(RecruiterDailyMetricsService::class)
                        ->actualFor($record, TargetMetric::Selections, now()->startOfMonth(), now()->endOfMonth())),
                TextColumn::make('joined')
                    ->label('Joined')
                    ->state(fn (Employee $record) => app(RecruiterDailyMetricsService::class)
                        ->actualFor($record, TargetMetric::Joining, now()->startOfMonth(), now()->endOfMonth())),
                TextColumn::make('achievement')
                    ->label('Achievement')
                    ->state(fn (Employee $record) => self::unweightedAchievement($record))
                    ->formatStateUsing(fn (?float $state) => $state !== null ? number_format($state, 1).'%' : '—'),
                TextColumn::make('score')
                    ->label('Score')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state !== null ? number_format((float) $state, 1) : '—'),
            ])
            ->defaultSort('score', 'desc')
            ->paginated([10, 25, 50]);
    }

    private function baseQuery(string $periodStart, string $periodEnd): Builder
    {
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
                    ->select('employee_id', 'score', 'breakdown'),
                'current_snapshot',
                'current_snapshot.employee_id',
                '=',
                'employees.id',
            )
            ->select('employees.*', 'current_snapshot.score', 'current_snapshot.breakdown');
    }

    private static function unweightedAchievement(Employee $record): ?float
    {
        $breakdown = json_decode((string) ($record->breakdown ?? ''), true) ?? [];
        $achievements = collect($breakdown)->pluck('achievement')->filter(fn ($v) => $v !== null);

        return $achievements->isEmpty() ? null : round($achievements->avg(), 1);
    }
}
