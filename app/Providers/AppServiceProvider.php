<?php

namespace App\Providers;

use App\Policies\RolePolicy;
use Filament\Facades\Filament;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Spatie's Role model lives outside App\Models, so Laravel's policy auto-discovery
        // (which only replaces a "Models" namespace segment) can't find RolePolicy on its own.
        Gate::policy(Role::class, RolePolicy::class);

        $this->configureTables();
    }

    /**
     * Project-wide table defaults. Record actions render in the first column, where the admin theme
     * (resources/css/filament/admin/theme.css) shows them in a strip as the hovered row expands. Every
     * row (list pages, relation managers, widgets) opens its record's view page, falling back to the
     * edit page — Filament's own list-page default would pick an EditAction's URL first whenever the
     * table has no ViewAction. Records without a resource page keep Filament's modal record action.
     * Any table that sets its own position or recordUrl() still overrides these defaults.
     */
    private function configureTables(): void
    {
        Table::configureUsing(fn (Table $table): Table => $table
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
            ->recordUrl(fn (mixed $record): ?string => self::resourceRecordUrl($record)));
    }

    private static function resourceRecordUrl(mixed $record): ?string
    {
        if (! $record instanceof Model) {
            return null;
        }

        $resource = Filament::getModelResource($record);

        if ($resource === null) {
            return null;
        }

        foreach (['view', 'edit'] as $page) {
            if ($resource::hasPage($page) && $resource::{'can'.ucfirst($page)}($record)) {
                return $resource::getUrl($page, ['record' => $record]);
            }
        }

        return null;
    }
}
