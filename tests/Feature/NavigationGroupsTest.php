<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\RecruiterPerformanceRules\RecruiterPerformanceRuleResource;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;

test('the panel registers an Overview navigation group first', function (): void {
    $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

    expect($panel->getNavigationGroups())->toContain('Overview')
        ->and($panel->getNavigationGroups()[0])->toBe('Overview');
});

test('the Dashboard page belongs to the Overview navigation group', function (): void {
    expect(Dashboard::getNavigationGroup())->toBe('Overview');
});

test('RecruiterPerformanceRuleResource belongs to the Performance navigation group, not Administration', function (): void {
    expect(RecruiterPerformanceRuleResource::getNavigationGroup())->toBe('Performance');
});
