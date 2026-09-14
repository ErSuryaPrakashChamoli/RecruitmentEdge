<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CandidateAgingWidget;
use App\Filament\Widgets\ConversionBreakdownWidget;
use App\Filament\Widgets\FollowUpCalendar;
use App\Filament\Widgets\InterviewAnalyticsWidget;
use App\Filament\Widgets\JoiningTrendChart;
use App\Filament\Widgets\OfferJoiningAnalyticsWidget;
use App\Filament\Widgets\PositionHealthWidget;
use App\Filament\Widgets\RecruiterLeaderboardWidget;
use App\Filament\Widgets\RecruitmentActionCenterWidget;
use App\Filament\Widgets\RecruitmentFunnelWidget;
use App\Filament\Widgets\RecruitmentInsightsWidget;
use App\Filament\Widgets\RecruitmentOverviewStats;
use App\Filament\Widgets\SlaTatWidget;
use App\Filament\Widgets\SmartRecommendationsWidget;
use App\Filament\Widgets\SourcePerformanceWidget;
use App\Filament\Widgets\TodaysRecruitmentPulse;
use App\Filament\Widgets\TurnUpTrendChart;
use App\Models\Employee;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\HierarchyService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * The Recruitment Command Center. Overrides the built-in Dashboard's getWidgets(), which
 * otherwise returns Filament::getWidgets() — every widget under app/Filament/Widgets, discovered
 * or not (including page-specific ones like IncentiveDashboardStats, which belongs only on the
 * Incentive Dashboard page). This is the explicit, curated set for the main dashboard, ordered by
 * what a recruiter needs to see first: today's numbers, what needs attention, the funnel, then
 * progressively deeper analytics.
 *
 * HasFiltersForm gives every widget below (via InteractsWithPageFilters) the same shared "period"
 * and "recruiter" filters — see App\Filament\Widgets\Concerns\ResolvesDashboardPeriod, which every
 * widget uses to read them consistently rather than each defining its own filter logic.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Deliberately NOT wrapped in a multi-column Grid: the filters form renders in a
                // narrow header-row slot regardless of viewport width, so a 2-4 column grid
                // squeezes each Select into ~60-80px and its selected-option text wraps
                // character-by-character. Stacking one field per row guarantees each field gets
                // the full width of whatever space it's given.
                Select::make('period')
                    ->label('Period')
                    ->options([
                        'today' => 'Today',
                        'yesterday' => 'Yesterday',
                        'this_week' => 'This Week',
                        'this_month' => 'This Month',
                        'last_month' => 'Last Month',
                        'last_30_days' => 'Last 30 Days',
                        'custom' => 'Custom Range',
                    ])
                    ->default('this_month')
                    ->live()
                    ->native(false),
                DatePicker::make('start')
                    ->label('From')
                    ->visible(fn ($get) => $get('period') === 'custom'),
                DatePicker::make('end')
                    ->label('To')
                    ->visible(fn ($get) => $get('period') === 'custom'),
                Select::make('recruiter_id')
                    ->label('Recruiter')
                    ->placeholder('All recruiters visible to me')
                    ->options(fn () => $this->recruiterOptions())
                    ->searchable()
                    ->native(false),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function recruiterOptions(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return Employee::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName()])
            ->all();
    }

    /**
     * Same widget set for every role (no data or permission changes here — HierarchyService
     * already scopes each widget's own query), ordered per the product document. Managers:
     * Overview, Pulse, Follow-up Calendar, Action Center, Smart Recommendations (+ Insights), Funnel,
     * then the analytics block. A plain recruiter (no one reporting to them) sees the Funnel right
     * after the Pulse — their own pipeline is what they work from — then the same sequence.
     *
     * @return array<int, class-string>
     */
    public function getWidgets(): array
    {
        $actionable = [
            FollowUpCalendar::class,
            RecruitmentActionCenterWidget::class,
            SmartRecommendationsWidget::class,
            RecruitmentInsightsWidget::class,
        ];

        $analytics = [
            RecruiterLeaderboardWidget::class,
            ConversionBreakdownWidget::class,
            PositionHealthWidget::class,
            SourcePerformanceWidget::class,
            CandidateAgingWidget::class,
            SlaTatWidget::class,
            InterviewAnalyticsWidget::class,
            OfferJoiningAnalyticsWidget::class,
            TurnUpTrendChart::class,
            JoiningTrendChart::class,
        ];

        if ($this->isIndividualContributor()) {
            return [
                RecruitmentOverviewStats::class,
                TodaysRecruitmentPulse::class,
                RecruitmentFunnelWidget::class,
                ...$actionable,
                ...$analytics,
            ];
        }

        return [
            RecruitmentOverviewStats::class,
            TodaysRecruitmentPulse::class,
            ...$actionable,
            RecruitmentFunnelWidget::class,
            ...$analytics,
        ];
    }

    /**
     * True for a plain recruiter with no one reporting to them (visibleEmployeeIdsFor() returns
     * just themselves) — not a permission check, purely "does this view have anyone to compare".
     */
    private function isIndividualContributor(): bool
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return $visibleIds !== null && $visibleIds->count() <= 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('askAi')
                ->label('Explain with AI')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn () => (bool) auth()->user()?->can('ai.query'))
                ->url(fn () => AiCopilot::linkForContext('dashboard')),
        ];
    }

    /**
     * Section 5's hero header — a time-of-day greeting plus the viewer's real name (employee name
     * where available, otherwise their login name), replacing the plain "Dashboard" title.
     */
    public function getHeading(): string
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $name = $user->employee?->fullName() ?? $user->name;

        // Greeting is always IST regardless of the app's own timezone (config('app.timezone') is
        // UTC) — this is a greeting shown to India-based users, not a data timestamp, so it must
        // reflect their clock rather than the server's.
        $nowIst = now('Asia/Kolkata');
        $greeting = match (true) {
            $nowIst->hour < 12 => 'Good Morning',
            $nowIst->hour < 17 => 'Good Afternoon',
            default => 'Good Evening',
        };

        return "{$greeting}, {$name}";
    }

    /**
     * The original description line, unchanged, plus a second line for the admin-configurable
     * "quote of the day" (App\Filament\Pages\DashboardQuoteSettings) — only appended when an admin
     * has actually set one, so a fresh install shows exactly what it did before this existed.
     */
    public function getSubheading(): string|Htmlable
    {
        $description = 'Recruitment Command Center — monitor hiring activity, recruiter performance and joining pipeline. '.now('Asia/Kolkata')->format('l, d F Y');

        $quote = RecruitmentSetting::get('dashboard.quote_text');

        if (blank($quote)) {
            return $description;
        }

        $icon = RecruitmentSetting::get('dashboard.quote_icon', 'heroicon-o-sparkles');

        return new HtmlString(
            e($description).
            '<span class="mt-1 flex items-center gap-1.5">'.
            Blade::render('<x-filament::icon :icon="$icon" class="h-4 w-4 shrink-0" />', ['icon' => $icon]).
            '<span>'.e($quote).'</span>'.
            '</span>',
        );
    }
}
