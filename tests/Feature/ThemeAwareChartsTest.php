<?php

use App\Filament\Widgets\SourcePerformanceWidget;
use App\Filament\Widgets\TurnUpTrendChart;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Widgets\ChartWidget;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * ChartWidget::getData() is protected, with no public accessor — reflection is the only way to
 * observe its output directly in a test without adding a test-only public method to production code.
 *
 * @return array<string, mixed>
 */
function callChartWidgetData(ChartWidget $widget): array
{
    $method = new ReflectionMethod($widget, 'getData');

    return $method->invoke($widget);
}

test('the turn-up trend chart\'s line-ups series uses the active theme color, while turn-ups/no-shows stay fixed semantic colors', function (): void {
    $navyUser = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => 'navy']);
    $navyUser->assignRole('recruiter');
    actingAs($navyUser);
    $navyData = callChartWidgetData(Livewire::test(TurnUpTrendChart::class)->instance());

    $coralUser = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => 'coral']);
    $coralUser->assignRole('recruiter');
    actingAs($coralUser);
    $coralData = callChartWidgetData(Livewire::test(TurnUpTrendChart::class)->instance());

    expect($navyData['datasets'][0]['borderColor'])->toBe('#1B3B6F')
        ->and($coralData['datasets'][0]['borderColor'])->toBe('#F4623A')
        ->and($navyData['datasets'][0]['borderColor'])->not->toBe($coralData['datasets'][0]['borderColor'])
        ->and($navyData['datasets'][1]['borderColor'])->toBe($coralData['datasets'][1]['borderColor'])
        ->and($navyData['datasets'][1]['borderColor'])->toBe('#22c55e')
        ->and($navyData['datasets'][2]['borderColor'])->toBe($coralData['datasets'][2]['borderColor'])
        ->and($navyData['datasets'][2]['borderColor'])->toBe('#ef4444');
});

test('source performance\'s doughnut palette is a monochromatic ramp of the active theme, not a fixed rainbow', function (): void {
    // The palette is computed unconditionally in getData(), independent of how many source rows
    // exist, so no seeded candidate data is needed to observe it changing with the active theme.
    $tealUser = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => 'teal']);
    $tealUser->assignRole('chro');
    actingAs($tealUser);
    $tealPalette = callChartWidgetData(Livewire::test(SourcePerformanceWidget::class)->instance())['datasets'][0]['backgroundColor'];

    $purpleUser = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => 'purple']);
    $purpleUser->assignRole('chro');
    actingAs($purpleUser);
    $purplePalette = callChartWidgetData(Livewire::test(SourcePerformanceWidget::class)->instance())['datasets'][0]['backgroundColor'];

    expect($tealPalette[0])->toBe('#0D9488')
        ->and($purplePalette[0])->toBe('#A21CAF')
        ->and($tealPalette)->not->toBe($purplePalette);
});

test('source performance labels stay a plain list when sorting reorders the sources', function (): void {
    $naukri = CandidateSource::factory()->create(['name' => 'Naukri']);
    $linkedIn = CandidateSource::factory()->create(['name' => 'LinkedIn']);

    CandidateApplication::factory()->create(['candidate_id' => Candidate::factory()->create(['source_id' => $naukri->id])->id]);
    CandidateApplication::factory()->count(3)->sequence(fn () => ['candidate_id' => Candidate::factory()->create(['source_id' => $linkedIn->id])->id])->create();

    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('chro');
    actingAs($user);

    $data = callChartWidgetData(Livewire::test(SourcePerformanceWidget::class)->instance());

    expect(array_is_list($data['labels']))->toBeTrue()
        ->and($data['labels'])->toBe(['LinkedIn', 'Naukri'])
        ->and($data['datasets'][0]['data'])->toBe([3, 1]);
});
