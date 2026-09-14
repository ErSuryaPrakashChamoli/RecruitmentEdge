<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('By')
                    ->formatStateUsing(fn (?string $state) => $state ?? 'System')
                    ->searchable(),
                TextColumn::make('auditable_type')
                    ->label('Model')
                    ->formatStateUsing(fn (string $state) => Str::headline(class_basename($state))),
                TextColumn::make('auditable_id')
                    ->label('Record #'),
                TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Str::headline($state))
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('changed_fields')
                    ->label('Fields')
                    ->state(fn (AuditLog $record): string => implode(', ', array_column($record->diffRows(), 'field')))
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('action')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                        'permissions_updated' => 'Role Permissions Updated',
                    ]),
                SelectFilter::make('auditable_type')
                    ->label('Model')
                    ->options(fn () => AuditLog::query()
                        ->distinct()
                        ->pluck('auditable_type', 'auditable_type')
                        ->mapWithKeys(fn ($type) => [$type => Str::headline(class_basename($type))])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('user.name')->label('By')->formatStateUsing(fn (?string $state) => $state ?? 'System'),
                        TextEntry::make('auditable_type')->label('Model')->formatStateUsing(fn (string $state) => Str::headline(class_basename($state))),
                        TextEntry::make('auditable_id')->label('Record #'),
                        TextEntry::make('action')->badge()->formatStateUsing(fn (string $state) => Str::headline($state)),
                        TextEntry::make('ip_address')->placeholder('—'),
                        TextEntry::make('diff')
                            ->label('Changes (old → new)')
                            ->state(fn (AuditLog $record): array => collect($record->diffRows())
                                ->map(fn (array $row): string => "{$row['field']}: {$row['old']} → {$row['new']}")
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ])
            ->toolbarActions([]);
    }
}
