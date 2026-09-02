<?php

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('dashboard sections other than the KPI row render collapsed by default with a collapse toggle', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    $response = actingAs($user)->get('/admin');

    $response->assertSuccessful();

    $html = $response->getContent();

    // Every dashboard widget below the KPI row got collapsible+collapsed — 15 of them when AI is
    // configured: 13 custom-Blade widgets plus TurnUpTrendChart and SourcePerformanceWidget, which
    // needed their own copy of chart-widget.blade.php (filament.widgets.collapsed-chart-widget)
    // since Filament's own ChartWidget view never passes a default-collapsed state through to the
    // section. Only 14 render here because SmartRecommendationsWidget::canView() hides itself when
    // AiGateway isn't configured (true in this test env) — that gate is pre-existing and unrelated
    // to collapsing, not a widget this test can force to appear.
    expect(substr_count($html, 'fi-section-collapse-btn'))->toBeGreaterThanOrEqual(14);

    // The content itself is still server-rendered (collapse is a client-side visual toggle via
    // x-cloak, not content that's stripped from the page), so real data stays assertable.
    $response->assertSee('Action Center')
        ->assertSee('Follow-up Calendar')
        ->assertSee('Recruitment Funnel')
        ->assertSee('Line-up vs Turn-up Trend')
        ->assertSee('Source Performance');
});

test('the two chart widgets also start collapsed, not just wrapped with the toggle', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    $html = actingAs($user)->get('/admin')->getContent();

    foreach (['Line-up vs Turn-up Trend', 'Source Performance'] as $heading) {
        $headingPosition = strpos($html, $heading);
        expect($headingPosition)->not->toBeFalse("Expected to find heading [{$heading}] on the dashboard.");

        // The section's content-container x-cloak attribute (only present when collapsed="true")
        // sits a short distance after the heading, before the next widget's section begins.
        $nextWidgetBoundary = strpos($html, 'fi-wi-chart', $headingPosition + strlen($heading));
        $searchWindow = substr($html, $headingPosition, ($nextWidgetBoundary ?: strlen($html)) - $headingPosition);

        expect($searchWindow)->toContain('x-cloak');
    }
});

test('the top KPI row is not wrapped in a collapsible section', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    $html = actingAs($user)->get('/admin')->getContent();

    // RecruitmentOverviewStats renders its own kpi-stat grid directly, with no
    // x-filament::section wrapper at all, so it can never end up collapsible by accident.
    $kpiRowStart = strpos($html, 'Open Positions');
    $nextSectionStart = strpos($html, 'fi-section-collapse-btn');

    expect($kpiRowStart)->not->toBeFalse()
        ->and($nextSectionStart)->not->toBeFalse()
        ->and($kpiRowStart)->toBeLessThan($nextSectionStart);
});
