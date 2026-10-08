<?php

namespace App\Filament\Platform\Pages;

use App\Enums\PlatformCapability;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Platform\PlatformDirectory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
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
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * SaaS-5: the platform audit — the platform's own stream and every platform action on a tenant
 * (lifecycle, support access and its use, ownership, deletion and purge, compliance exports), with
 * who, when and why. Read-only: the audit trail is append-only.
 */
class PlatformAudit extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $title = 'Platform audit';

    #[Url(as: 'tenant')]
    public ?int $tenantFilter = null;

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::AuditView);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(PlatformDirectory::class)->audit()->with('tenant:id,slug')->when($this->tenantFilter !== null, fn (Builder $query) => $query->where('tenant_id', $this->tenantFilter)))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime(),
                TextColumn::make('tenant.slug')->label('Tenant')->placeholder('Platform'),
                TextColumn::make('action')->searchable(),
                TextColumn::make('actor_kind')->label('Actor')->badge(),
                TextColumn::make('user.name')->label('By')->description(fn (AuditLog $record): ?string => $record->user?->email)->placeholder('—'),
                TextColumn::make('auditable_type')->label('Subject')->formatStateUsing(fn (?string $state, AuditLog $record): string => class_basename((string) $state).' #'.$record->auditable_id),
                TextColumn::make('reason')->limit(50)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('action')->options(array_combine(PlatformDirectory::PLATFORM_ACTIONS, PlatformDirectory::PLATFORM_ACTIONS)),
                SelectFilter::make('actor_kind')->label('Actor')->options(array_combine(AuditLog::ACTOR_KINDS, AuditLog::ACTOR_KINDS)),
            ])
            ->headerActions([
                Action::make('allTenants')
                    ->label(fn (): string => 'Tenant: '.(Tenant::query()->whereKey($this->tenantFilter)->value('slug') ?? '—').' (show all)')
                    ->visible(fn (): bool => $this->tenantFilter !== null)
                    ->color('gray')
                    ->action(fn () => $this->tenantFilter = null),
            ])
            ->recordActions([
                Action::make('details')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->schema([
                        KeyValueEntry::make('changes')->state(fn (AuditLog $record): array => collect((array) $record->changes)->map(fn ($value) => is_scalar($value) || $value === null ? (string) ($value ?? '—') : (string) json_encode($value))->all()),
                        KeyValueEntry::make('old_values')->label('Before')->state(fn (AuditLog $record): array => collect((array) $record->old_values)->map(fn ($value) => is_scalar($value) || $value === null ? (string) ($value ?? '—') : (string) json_encode($value))->all()),
                    ]),
            ])
            ->recordUrl(null);
    }
}
