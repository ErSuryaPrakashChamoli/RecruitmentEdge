<?php

use App\Filament\Resources\AiActionLogs\Pages\ListAiActionLogs;
use App\Filament\Resources\AiUsageLogs\Pages\ListAiUsageLogs;
use App\Models\AiActionLog;
use App\Models\AiUsageLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('chro');
    actingAs($admin);
});

test('the AI usage screen totals requests, tokens, and cost for the filtered date range', function (): void {
    AiUsageLog::factory()->create(['cost' => 0.25, 'input_tokens' => 100, 'output_tokens' => 50, 'created_at' => now()->subDays(10)]);
    AiUsageLog::factory()->create(['cost' => 0.5, 'input_tokens' => 200, 'output_tokens' => 70, 'created_at' => now()]);

    Livewire::test(ListAiUsageLogs::class)
        ->assertTableColumnSummarySet('cost', 'total_cost', 0.75)
        ->assertTableColumnSummarySet('request_type', 'requests', 2)
        ->filterTable('created_at', ['from' => now()->subDay()->toDateString()])
        ->assertTableColumnSummarySet('cost', 'total_cost', 0.5)
        ->assertTableColumnSummarySet('input_tokens', 'input_tokens', 200)
        ->assertTableColumnSummarySet('request_type', 'requests', 1);
});

test('the AI action audit shows an action\'s input parameters and entity ids in its details view', function (): void {
    $log = AiActionLog::factory()->create([
        'tool_name' => 'assign_candidates_to_recruiter',
        'entity_type' => 'CandidateApplication',
        'entity_ids' => [11, 12],
        'input' => ['application_ids' => [11, 12], 'recruiter_id' => 7],
        'output' => ['success' => true, 'summary' => 'Reassigned 2 application(s).'],
    ]);

    Livewire::test(ListAiActionLogs::class)
        ->mountAction(TestAction::make('view')->table($log))
        ->assertMountedActionModalSee('recruiter_id')
        ->assertMountedActionModalSee('11, 12');
});
