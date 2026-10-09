<?php

namespace App\Filament\Platform\Pages;

use App\Enums\BillingInterval;
use App\Enums\PlatformCapability;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\BillingSubscription;
use App\Models\Tenant;
use App\Services\Platform\PlatformDirectory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Platform commercial UI: every tenant's subscriptions as SaaS-4 holds them — status, price
 * snapshot, source, periods. Read-only here: subscription changes are made on the tenant's detail
 * page (Billing), through the billing services.
 */
class Subscriptions extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Subscriptions';

    #[Url(as: 'tenant')]
    public ?int $tenantFilter = null;

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
            ->query(fn (): Builder => $directory->subscriptions()->when($this->tenantFilter !== null, fn (Builder $query) => $query->where('tenant_id', $this->tenantFilter)))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('Tenant')->description(fn (BillingSubscription $record): ?string => $record->tenant?->slug),
                TextColumn::make('planVersion.plan.name')->label('Plan')->description(fn (BillingSubscription $record): ?string => $record->planVersion !== null ? 'v'.$record->planVersion->version : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (SubscriptionStatus $state): string => $state->label())
                    ->color(fn (SubscriptionStatus $state): string => Tenants::subscriptionColor($state)),
                TextColumn::make('amount_minor')->label('Price')
                    ->formatStateUsing(fn (BillingSubscription $record): string => $record->money()->format().' / '.$record->interval->label()),
                TextColumn::make('source')->label('Billed')->formatStateUsing(fn (SubscriptionSource $state): string => $state->name),
                TextColumn::make('contract_reference')->label('Contract')->placeholder('—')->toggleable(),
                TextColumn::make('activated_at')->label('Active since')->date()->placeholder('—')->sortable(),
                TextColumn::make('current_period_end')->label('Period ends')->date()->placeholder('—')
                    ->description(fn (BillingSubscription $record): ?string => $record->current_period_start?->toFormattedDateString()),
                TextColumn::make('trial_end')->label('Trial ends')->date()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancel_at')->label('Ends at')->date()->placeholder('—')->toggleable(),
                TextColumn::make('past_due_since')->label('Past due since')->date()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('grace_ends_at')->label('Grace ends')->date()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_live')->label('Live')->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->where('is_live', true),
                        false: fn (Builder $query) => $query->whereNull('is_live'),
                    ),
                SelectFilter::make('status')->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn (SubscriptionStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('source')->label('Billed')->options(collect(SubscriptionSource::cases())->mapWithKeys(fn (SubscriptionSource $source): array => [$source->value => $source->name])->all()),
                SelectFilter::make('interval')->options(collect(BillingInterval::cases())->mapWithKeys(fn (BillingInterval $interval): array => [$interval->value => $interval->label()])->all()),
                SelectFilter::make('plan')
                    ->options(fn (): array => $directory->planOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $directory->whereSubscriptionPlan($query, (string) $data['value']) : $query),
                Filter::make('started')
                    ->schema([DatePicker::make('from')->label('Started from'), DatePicker::make('until')->label('Started until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->headerActions([
                Action::make('allTenants')
                    ->label(fn (): string => 'Tenant: '.(Tenant::query()->whereKey($this->tenantFilter)->value('slug') ?? '—').' (show all)')
                    ->visible(fn (): bool => $this->tenantFilter !== null)
                    ->color('gray')
                    ->action(fn () => $this->tenantFilter = null),
            ])
            ->recordUrl(fn (BillingSubscription $record): string => TenantDetail::getUrl(['tenant' => $record->tenant_id]))
            ->emptyStateHeading('No subscriptions');
    }
}
