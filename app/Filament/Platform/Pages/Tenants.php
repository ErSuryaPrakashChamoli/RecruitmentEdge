<?php

namespace App\Filament\Platform\Pages;

use App\Enums\DeletionRequestStatus;
use App\Enums\PlatformCapability;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * SaaS-5: every tenant's operational state — status, plan, members, open platform work. Metadata
 * only; a tenant's records are never listed here.
 */
class Tenants extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Tenants';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Tenants';

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::TenantsView);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $directory = app(PlatformDirectory::class);

        return $table
            ->query(fn () => $directory->tenants())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->description(fn (Tenant $record): string => $record->slug)->searchable(['name', 'slug'])->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Tenant $record): TenantStatus => $record->effectiveStatus())
                    ->formatStateUsing(fn (TenantStatus $state): string => $state->label())
                    ->color(fn (TenantStatus $state): string => $state->color())
                    ->tooltip(fn (Tenant $record): ?string => $record->status_reason),
                TextColumn::make('plan_name')->label('Plan')->placeholder('None')
                    ->description(fn (Tenant $record): ?string => $record->getAttribute('plan_version') !== null ? 'v'.$record->getAttribute('plan_version') : null),
                TextColumn::make('subscription_status')->label('Subscription')->badge()->placeholder('None')
                    ->formatStateUsing(fn (?string $state): string => SubscriptionStatus::tryFrom((string) $state)?->label() ?? '—')
                    ->color(fn (?string $state): string => self::subscriptionColor(SubscriptionStatus::tryFrom((string) $state))),
                TextColumn::make('billing_state')->label('Billing')->badge()
                    ->state(fn (Tenant $record): string => PlatformDirectory::billingState($record))
                    ->formatStateUsing(fn (string $state): string => PlatformDirectory::billingStates()[$state])
                    ->color(fn (string $state): string => match ($state) {
                        'past_due' => 'danger',
                        'invoice_due' => 'warning',
                        'up_to_date' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('owner_name')->label('Owner')->placeholder('No owner recorded')->toggleable(),
                TextColumn::make('active_members')->label('Active members')->numeric(),
                TextColumn::make('trial_ends_at')->label('Trial ends')->date()->placeholder('—')->sortable(),
                TextColumn::make('deletion_status')
                    ->label('Deletion')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => DeletionRequestStatus::tryFrom((string) $state)?->label() ?? '—')
                    ->color(fn (?string $state): string => DeletionRequestStatus::tryFrom((string) $state)?->color() ?? 'gray')
                    ->placeholder('—'),
                TextColumn::make('active_support_grants')->label('Support grants')->numeric()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Created')->date()->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(TenantStatus::cases())->mapWithKeys(fn (TenantStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('plan')
                    ->options(fn (): array => $directory->planOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $directory->wherePlan($query, (string) $data['value']) : $query),
                SelectFilter::make('subscription')
                    ->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn (SubscriptionStatus $status): array => [$status->value => $status->label()])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $directory->whereSubscriptionStatus($query, (string) $data['value']) : $query),
                SelectFilter::make('billing')
                    ->options(PlatformDirectory::billingStates())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $directory->whereBillingState($query, (string) $data['value']) : $query),
                Filter::make('trial_ending')->label('Trial ends within 7 days')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('tenants.status', TenantStatus::Trial->value)->whereBetween('tenants.trial_ends_at', [now(), now()->addDays(7)])),
                Filter::make('created')
                    ->schema([DatePicker::make('created_from')->label('Created from'), DatePicker::make('created_until')->label('Created until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('tenants.created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('tenants.created_at', '<=', $date))),
            ])
            ->headerActions([
                Action::make('create')->label('Create tenant')->icon('heroicon-o-plus')
                    ->visible(fn (): bool => CreateTenant::canAccess())
                    ->url(fn (): string => CreateTenant::getUrl()),
            ])
            ->recordUrl(fn (Tenant $record): string => TenantDetail::getUrl(['tenant' => $record->getKey()]));
    }

    public static function subscriptionColor(?SubscriptionStatus $status): string
    {
        return match ($status) {
            SubscriptionStatus::Active, SubscriptionStatus::Trialing => 'success',
            SubscriptionStatus::Pending, SubscriptionStatus::Cancelling => 'warning',
            SubscriptionStatus::PastDue, SubscriptionStatus::Unpaid => 'danger',
            default => 'gray',
        };
    }
}
