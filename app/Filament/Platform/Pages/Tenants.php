<?php

namespace App\Filament\Platform\Pages;

use App\Enums\DeletionRequestStatus;
use App\Enums\PlatformCapability;
use App\Enums\TenantStatus;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\Tenant;
use App\Services\Platform\PlatformDirectory;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
        return $table
            ->query(fn () => app(PlatformDirectory::class)->tenants())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->description(fn (Tenant $record): string => $record->slug)->searchable(['name', 'slug'])->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Tenant $record): TenantStatus => $record->effectiveStatus())
                    ->formatStateUsing(fn (TenantStatus $state): string => $state->label())
                    ->color(fn (TenantStatus $state): string => $state->color())
                    ->tooltip(fn (Tenant $record): ?string => $record->status_reason),
                TextColumn::make('plan_name')->label('Plan')->placeholder('None'),
                TextColumn::make('active_members')->label('Active members')->numeric(),
                TextColumn::make('trial_ends_at')->label('Trial ends')->date()->placeholder('—')->sortable(),
                TextColumn::make('deletion_status')
                    ->label('Deletion')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => DeletionRequestStatus::tryFrom((string) $state)?->label() ?? '—')
                    ->color(fn (?string $state): string => DeletionRequestStatus::tryFrom((string) $state)?->color() ?? 'gray')
                    ->placeholder('—'),
                TextColumn::make('active_support_grants')->label('Support grants')->numeric(),
                TextColumn::make('created_at')->label('Created')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(TenantStatus::cases())->mapWithKeys(fn (TenantStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordUrl(fn (Tenant $record): string => TenantDetail::getUrl(['tenant' => $record->getKey()]));
    }
}
