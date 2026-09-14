<?php

use App\Enums\TargetMetric;
use App\Filament\Exports\RecruiterPerformanceSnapshotExporter;
use App\Filament\Resources\RecruiterPerformanceSnapshots\Pages\ListRecruiterPerformanceSnapshots;
use App\Models\RecruiterPerformanceSnapshot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    $this->snapshot = RecruiterPerformanceSnapshot::factory()->create([
        'score' => 95,
        'breakdown' => [
            ['metric' => TargetMetric::ConnectedCalls->value, 'weight' => 40, 'target' => 10, 'actual' => 12, 'achievement' => 120.0],
            ['metric' => TargetMetric::Screening->value, 'weight' => 30, 'target' => 10, 'actual' => 6, 'achievement' => 60.0],
            ['metric' => TargetMetric::Offers->value, 'weight' => 30, 'target' => 4, 'actual' => 4, 'achievement' => 100.0],
        ],
    ]);
});

test('the snapshot list shows metrics met and controllable versus influenced achievement', function (): void {
    Livewire::test(ListRecruiterPerformanceSnapshots::class)
        ->assertTableColumnStateSet('metrics_met', '2 / 3', $this->snapshot)
        ->assertTableColumnStateSet('controllable_achievement', 90.0, $this->snapshot)
        ->assertTableColumnStateSet('influenced_achievement', 100.0, $this->snapshot);
});

test('the breakdown modal shows metric labels grouped into controllable and influenced sections', function (): void {
    Livewire::test(ListRecruiterPerformanceSnapshots::class)
        ->mountAction(TestAction::make('view')->table($this->snapshot))
        ->assertMountedActionModalSee(['Controllable Metrics', 'Influenced Metrics', 'Connected Calls', 'Offers', '1 of 2 targeted metrics met'])
        ->assertMountedActionModalDontSee('connected_calls');
});

test('the exporter includes target, actual, and achievement columns for every metric', function (): void {
    $columnNames = collect(RecruiterPerformanceSnapshotExporter::getColumns())->map->getName();

    foreach (TargetMetric::cases() as $metric) {
        expect($columnNames)->toContain("{$metric->value}_target", "{$metric->value}_actual", "{$metric->value}_achievement");
    }

    $exporter = new RecruiterPerformanceSnapshotExporter(new Export, [
        'connected_calls_target' => 'Connected Calls Target',
        'connected_calls_actual' => 'Connected Calls Actual',
        'connected_calls_achievement' => 'Connected Calls Achievement %',
        'joining_target' => 'Joining Target',
        'metrics_met' => 'Metrics Met',
    ], []);

    expect($exporter($this->snapshot))->toEqual(['10', '12', '120', null, '2 / 3']);
});
