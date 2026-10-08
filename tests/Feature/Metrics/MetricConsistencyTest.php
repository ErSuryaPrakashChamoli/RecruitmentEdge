<?php

use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Filament\Pages\RecruitmentReports;
use App\Filament\Widgets\OfferJoiningAnalyticsWidget;
use App\Filament\Widgets\RecruitmentFunnelWidget;
use App\Filament\Widgets\RecruitmentOverviewStats;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Offer;
use App\Models\RecruitmentCost;
use App\Models\User;
use App\Services\AI\Tools\OfferTools\AnalyzeOffersTool;
use App\Services\AI\Tools\RecruitmentTools\AnalyzeFunnelTool;
use App\Services\AI\Tools\RecruitmentTools\TimeToHireTool;
use App\Services\RecruitmentSlaService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.5 (§8.5.8): the same metric, viewer and period give the same number on the dashboard,
 * the report, the SLA widget and in Copilot.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->chro = User::factory()->create()->assignRole('chro');
    actingAs($this->chro);
    $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(10, 0));

    foreach ([6, 9, 15] as $days) {
        lifecycleFixture(fn () => CandidateJoining::factory()->create([
            'candidate_application_id' => CandidateApplication::factory()->create(['application_date' => now()->subDays($days)])->id,
            'status' => JoiningStatus::Joined,
            'actual_doj' => now()->toDateString(),
        ]));
    }

    foreach ([OfferStatus::Accepted, OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Expired] as $status) {
        Offer::factory()->create(['status' => $status, 'offer_date' => now()])
            ->statusHistory()->create(['from_status' => OfferStatus::Initiated, 'to_status' => OfferStatus::Released]);
    }

    RecruitmentCost::factory()->create(['amount' => 30000, 'incurred_on' => now()]);
    $this->monthRange = ['start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString()];
});

test('time to hire is the same on the dashboard, the report, the SLA widget and in Copilot', function (): void {
    $card = collect(Livewire::test(RecruitmentOverviewStats::class, ['pageFilters' => ['period' => 'this_month']])->instance()->getCards())->firstWhere('label', 'Median Time to Hire');
    $report = Livewire::test(RecruitmentReports::class)->instance()->getTimeToHire();
    $sla = app(RecruitmentSlaService::class)->timeToHireSummary(now()->startOfMonth(), now()->endOfMonth(), $this->chro);
    $copilot = app(TimeToHireTool::class)->handle($this->monthRange, $this->chro)->data;

    expect($report->value)->toBe(9.0)
        ->and($card['value'])->toBe($report->display())
        ->and($sla['median_days'])->toBe($report->value)
        ->and($copilot['median_time_to_hire_days'])->toBe($report->value);
});

test('cost per hire is the same on the dashboard, the report and in Copilot', function (): void {
    $card = collect(Livewire::test(RecruitmentOverviewStats::class, ['pageFilters' => ['period' => 'this_month']])->instance()->getCards())->firstWhere('label', 'Cost per Hire');
    $report = Livewire::test(RecruitmentReports::class)->instance()->getCostPerHire();
    $copilot = app(TimeToHireTool::class)->handle($this->monthRange, $this->chro)->data;

    expect($report->value)->toBe(10000.0)
        ->and($card['value'])->toBe($report->display())
        ->and($copilot['cost_per_hire'])->toBe($report->value);
});

test('offer acceptance is the same on the dashboard and in Copilot', function (): void {
    $widget = Livewire::test(OfferJoiningAnalyticsWidget::class, ['pageFilters' => ['period' => 'this_month']])->instance();
    $copilot = app(AnalyzeOffersTool::class)->handle($this->monthRange, $this->chro)->data;

    expect($widget->getMetric('offer.acceptance_rate')->value)->toBe(50.0)
        ->and($widget->getOffers()['acceptance_percent'])->toBe(50.0)
        ->and($copilot['acceptance_rate_pct'])->toBe(50.0);
});

test('the funnel is the same on the dashboard, the report and in Copilot', function (): void {
    $widget = Livewire::test(RecruitmentFunnelWidget::class, ['pageFilters' => ['period' => 'this_month']])->instance()->getRows()->keyBy(fn (array $row) => $row['stage']->value);
    $report = Livewire::test(RecruitmentReports::class)->instance()->getFunnel()->keyBy(fn (array $row) => $row['stage']->value);
    $copilot = collect(app(AnalyzeFunnelTool::class)->handle($this->monthRange, $this->chro)->data['funnel'])->keyBy('stage');

    expect($widget['joined']['count'])->toBe($report['joined']['count'])
        ->and($copilot['Joined']['reached'])->toBe($report['joined']['count'])
        ->and($copilot['Joined']['percent_of_applications'])->toBe($report['joined']['conversion_from_sourced']);
});
