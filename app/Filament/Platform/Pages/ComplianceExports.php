<?php

namespace App\Filament\Platform\Pages;

use App\Enums\ComplianceExportStatus;
use App\Enums\PlatformCapability;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\ComplianceExport;
use App\Models\User;
use App\Services\Platform\ComplianceExportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * SaaS-5: the compliance export register — what was exported, for which tenant, by whom, when and
 * why, its checksum, downloads and expiry. Downloads are audited; expired artifacts are deleted.
 */
class ComplianceExports extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $title = 'Compliance exports';

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::ComplianceManage);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ComplianceExport::query()->with(['tenant:id,name,slug', 'requester:id,name']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('tenant.name')->label('Tenant')->description(fn (ComplianceExport $record): ?string => $record->tenant?->slug),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ComplianceExportStatus $state): string => $state->label())
                    ->color(fn (ComplianceExportStatus $state): string => $state->color()),
                TextColumn::make('requester.name')->label('Requested by')->description(fn (ComplianceExport $record): ?string => $record->requested_at?->toDayDateTimeString()),
                TextColumn::make('reason')->limit(40)->tooltip(fn (ComplianceExport $record): ?string => $record->reason),
                TextColumn::make('bytes')->label('Size')->formatStateUsing(fn (?int $state): string => $state === null ? '—' : number_format($state / 1024, 1).' KB'),
                TextColumn::make('sha256')->label('SHA-256')->limit(12)->copyable()->placeholder('—'),
                TextColumn::make('download_count')->label('Downloads')->numeric(),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('—'),
                TextColumn::make('error')->limit(40)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (ComplianceExport $record): bool => $record->status === ComplianceExportStatus::Ready)
                    ->action(fn (ComplianceExport $record) => self::perform(fn (User $operator) => app(ComplianceExportService::class)->download($record, $operator), 'Download started')),
            ])
            ->recordUrl(null);
    }
}
