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
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('action')
                    ->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']),
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
                        TextEntry::make('action')->badge(),
                        TextEntry::make('ip_address')->placeholder('—'),
                        TextEntry::make('changes')
                            ->label('Changes')
                            ->formatStateUsing(fn (?array $state) => $state ? json_encode($state, JSON_PRETTY_PRINT) : '—')
                            ->columnSpanFull(),
                    ]),
            ])
            ->toolbarActions([]);
    }
}
