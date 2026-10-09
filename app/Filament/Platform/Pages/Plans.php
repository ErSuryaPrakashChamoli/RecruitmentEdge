<?php

namespace App\Filament\Platform\Pages;

use App\Enums\PlanStatus;
use App\Enums\PlanVersionStatus;
use App\Enums\PlatformCapability;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\BillingPrice;
use App\Models\PlanVersion;
use App\Services\Platform\PlatformDirectory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Platform commercial UI: the plan catalog as published — every plan version, what it grants by
 * entitlement, its prices (current and retired) and how many tenants are on it. Read-only: plans
 * and prices are published by PlanCatalogService (plans:sync) and PriceCatalogService
 * (billing:price). Plan authoring (A1, docs/platform-commercial-ui.md) would add its actions to
 * this table and its view without changing the page.
 */
class Plans extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Plan catalog';

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::CommercialManage);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $directory = app(PlatformDirectory::class);

        return $table
            ->query(fn (): Builder => $directory->planCatalog())
            ->defaultSort('plan_id')
            ->columns([
                TextColumn::make('plan.name')->label('Plan')->description(fn (PlanVersion $record): string => (string) $record->plan?->code),
                TextColumn::make('plan.status')->label('Offered')->badge()
                    ->formatStateUsing(fn (PlanStatus $state): string => match ($state) {
                        PlanStatus::Active => 'To new tenants',
                        PlanStatus::Internal => 'Internal only',
                        PlanStatus::Retired => 'Retired',
                    })
                    ->color(fn (PlanStatus $state): string => match ($state) {
                        PlanStatus::Active => 'success',
                        PlanStatus::Internal => 'gray',
                        PlanStatus::Retired => 'danger',
                    }),
                TextColumn::make('version')->formatStateUsing(fn (int $state): string => "v{$state}"),
                TextColumn::make('status')->label('Version')->badge()
                    ->formatStateUsing(fn (PlanVersionStatus $state): string => ucfirst($state->value))
                    ->color(fn (PlanVersionStatus $state): string => $state === PlanVersionStatus::Published ? 'success' : 'gray'),
                TextColumn::make('published_at')->label('Published')->date()->placeholder('—'),
                TextColumn::make('tenants_count')->label('Tenants on it')->numeric(),
                TextColumn::make('current_prices')->label('Current prices')->numeric(),
            ])
            ->filters([
                SelectFilter::make('plan_status')->label('Offered')
                    ->options([PlanStatus::Active->value => 'To new tenants', PlanStatus::Internal->value => 'Internal only', PlanStatus::Retired->value => 'Retired'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->whereHas('plan', fn (Builder $plan) => $plan->where('status', $data['value'])) : $query),
            ])
            ->recordActions([
                Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (PlanVersion $record): string => $record->label())
                    ->modalDescription(fn (PlanVersion $record): ?string => $record->plan?->description)
                    ->modalSubmitAction(false)
                    ->schema([
                        KeyValueEntry::make('grants')->label('What this version grants')->keyLabel('Entitlement')->valueLabel('Value')
                            ->state(fn (PlanVersion $record): array => $directory->grants($record)),
                        RepeatableEntry::make('prices')->label('Prices')
                            ->state(fn (PlanVersion $record): array => $directory->prices((int) $record->getKey())->map(fn (BillingPrice $price): array => [
                                'amount' => $price->money()->format(),
                                'interval' => $price->interval->label(),
                                'state' => $price->is_current ? 'Current' : 'Retired',
                                'from' => $price->effective_from?->toFormattedDateString(),
                                'until' => $price->effective_until?->toFormattedDateString(),
                            ])->all())
                            ->placeholder('No price published')
                            ->table([TableColumn::make('Amount'), TableColumn::make('Interval'), TableColumn::make('State'), TableColumn::make('From'), TableColumn::make('Until')])
                            ->schema([
                                TextEntry::make('amount'),
                                TextEntry::make('interval'),
                                TextEntry::make('state')->badge()->color(fn (string $state): string => $state === 'Current' ? 'success' : 'gray'),
                                TextEntry::make('from')->placeholder('—'),
                                TextEntry::make('until')->placeholder('—'),
                            ]),
                    ]),
            ])
            ->recordUrl(null)
            ->paginated([25, 50])
            ->emptyStateHeading('No plans published');
    }
}
