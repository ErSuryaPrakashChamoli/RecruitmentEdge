<?php

namespace App\Filament\Pages;

use App\Services\Integrations\IntegrationRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Phase 5: the honest state of every external integration — implemented (an adapter exists),
 * configured (credentials present in the environment) and operational (an explicit connection
 * test succeeded). Secrets are never shown; tests are audited.
 */
class Integrations extends Page
{
    protected string $view = 'filament.pages.integrations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'Integrations';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('integrations.manage');
    }

    /**
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function getIntegrations(): Collection
    {
        return app(IntegrationRegistry::class)->overview()->groupBy('category');
    }

    public function testIntegrationAction(): Action
    {
        return Action::make('testIntegration')
            ->label('Test connection')
            ->requiresConfirmation()
            ->modalDescription('Calls the provider with the configured credentials to confirm it works. No message is sent to candidates.')
            ->action(function (array $arguments): void {
                abort_unless(static::canAccess(), 403);

                $status = app(IntegrationRegistry::class)->test((string) $arguments['key'], auth()->user()?->employee);

                Notification::make()
                    ->title($status->last_test_ok ? 'Integration is operational' : 'Connection test failed')
                    ->body($status->last_test_message)
                    ->color($status->last_test_ok ? 'success' : 'danger')
                    ->send();
            });
    }
}
