<?php

use App\Filament\Pages\Dashboard;
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
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a manager sees the widgets in the product document order', function (): void {
    $manager = Employee::factory()->create();
    Employee::factory()->reportingTo($manager)->create();

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');
    actingAs($user);

    expect((new Dashboard)->getWidgets())->toBe([
        RecruitmentOverviewStats::class,
        TodaysRecruitmentPulse::class,
        FollowUpCalendar::class,
        RecruitmentActionCenterWidget::class,
        SmartRecommendationsWidget::class,
        RecruitmentInsightsWidget::class,
        RecruitmentFunnelWidget::class,
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
    ]);
});

test('a plain recruiter with no reports sees the funnel right after the pulse, before the calendar', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    expect(array_slice((new Dashboard)->getWidgets(), 0, 7))->toBe([
        RecruitmentOverviewStats::class,
        TodaysRecruitmentPulse::class,
        RecruitmentFunnelWidget::class,
        FollowUpCalendar::class,
        RecruitmentActionCenterWidget::class,
        SmartRecommendationsWidget::class,
        RecruitmentInsightsWidget::class,
    ]);
});

test('every widget class appears exactly once regardless of role', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    $widgets = (new Dashboard)->getWidgets();

    expect($widgets)->toHaveCount(count(array_unique($widgets)));
});
