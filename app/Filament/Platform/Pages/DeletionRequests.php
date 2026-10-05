<?php

namespace App\Filament\Platform\Pages;

use App\Enums\DeletionRequestStatus;
use App\Enums\PlatformCapability;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\TenantDeletionRequest;
use App\Models\User;
use App\Services\Platform\TenantDeletionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
 * SaaS-5: tenant deletions — requested, approved by a second operator, waiting out their grace
 * period, purging, purged or failed (with the error and progress). Approve, cancel (until the purge
 * starts) and start a due purge; TenantDeletionService decides each.
 */
class DeletionRequests extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static string|UnitEnum|null $navigationGroup = 'Tenants';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Deletions';

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::DeletionManage);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => TenantDeletionRequest::query()->with(['tenant:id,name,slug,status', 'requester:id,name', 'approver:id,name']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('Tenant')->description(fn (TenantDeletionRequest $record): ?string => $record->tenant?->slug),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (DeletionRequestStatus $state): string => $state->label())
                    ->color(fn (DeletionRequestStatus $state): string => $state->color()),
                TextColumn::make('requester.name')->label('Requested by')->description(fn (TenantDeletionRequest $record): ?string => $record->requested_at?->toDayDateTimeString()),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—'),
                TextColumn::make('purge_after')->label('Purge after')->dateTime()->placeholder('—'),
                TextColumn::make('progress')->label('Progress')
                    ->state(fn (TenantDeletionRequest $record): string => count((array) ($record->progress['tables'] ?? [])).' tables, '.($record->progress['files_removed'] ?? 0).' files'),
                TextColumn::make('attempts')->label('Runs')->numeric(),
                TextColumn::make('failures')->numeric(),
                TextColumn::make('last_error')->label('Error')->limit(40)->placeholder('—')->tooltip(fn (TenantDeletionRequest $record): ?string => $record->last_error),
                TextColumn::make('reason')->limit(40)->tooltip(fn (TenantDeletionRequest $record): ?string => $record->reason),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(DeletionRequestStatus::cases())->mapWithKeys(fn (DeletionRequestStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => 'The tenant becomes deletion-pending; its data is purged after '.config('platform.deletion.grace_days').' days unless the deletion is cancelled. A different operator than the requester must approve.')
                    ->visible(fn (TenantDeletionRequest $record): bool => $record->status === DeletionRequestStatus::Requested)
                    ->action(fn (TenantDeletionRequest $record) => self::perform(fn (User $operator) => app(TenantDeletionService::class)->approve($record, $operator), 'Deletion approved')),
                Action::make('cancel')
                    ->color('gray')
                    ->visible(fn (TenantDeletionRequest $record): bool => in_array($record->status, [DeletionRequestStatus::Requested, DeletionRequestStatus::Approved], true))
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(fn (TenantDeletionRequest $record, array $data) => self::perform(fn (User $operator) => app(TenantDeletionService::class)->cancel($record, (string) $data['reason'], $operator), 'Deletion cancelled')),
                Action::make('purge')
                    ->label('Purge now')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Deletes this tenant\'s data permanently (retained records stay). Runs in the background and can be resumed.')
                    ->visible(fn (TenantDeletionRequest $record): bool => in_array($record->status, [DeletionRequestStatus::Approved, DeletionRequestStatus::Failed, DeletionRequestStatus::Purging], true) && $record->purge_after !== null && $record->purge_after->isPast())
                    ->action(fn (TenantDeletionRequest $record) => self::perform(fn (User $operator) => app(TenantDeletionService::class)->purgeNow($record, $operator), 'Purge queued')),
            ])
            ->recordUrl(fn (TenantDeletionRequest $record): string => TenantDetail::getUrl(['tenant' => $record->tenant_id]));
    }
}
