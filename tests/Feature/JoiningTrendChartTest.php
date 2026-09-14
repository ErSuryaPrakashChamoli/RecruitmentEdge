<?php

use App\Enums\JoiningStatus;
use App\Filament\Widgets\JoiningTrendChart;
use App\Models\CandidateJoining;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

test('the joining trend chart plots expected joiners against joined for the period', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'expected_doj' => now(), 'actual_doj' => now()]);

    $widget = Livewire::test(JoiningTrendChart::class)->instance();
    $data = (fn () => $this->getData())->call($widget);

    expect(collect($data['datasets'])->pluck('label')->all())->toBe(['Expected Joiners', 'Joined'])
        ->and(array_sum($data['datasets'][0]['data']))->toBe(1)
        ->and(array_sum($data['datasets'][1]['data']))->toBe(1);
});
