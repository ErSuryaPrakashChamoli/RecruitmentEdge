<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                        'created', 'restored' => 'success',
                        'deleted', 'force_deleted', 'archived' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('reason')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('request_id')
                    ->label('Request')
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                        'archived' => 'Archived',
                        'restored' => 'Restored',
                        'force_deleted' => 'Permanently Deleted',
                        'deactivated' => 'Deactivated',
                        'activated' => 'Activated',
                        'setting_changed' => 'Setting Changed',
                        'permissions_updated' => 'Role Permissions Updated',
                    ]),
                // Phase 8.6 (D8.6-024): who, when and which request — a change can be followed end to end.
                SelectFilter::make('user_id')
                    ->label('By')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data): ?string => match (true) {
                        filled($data['from'] ?? null) && filled($data['until'] ?? null) => "{$data['from']} – {$data['until']}",
                        filled($data['from'] ?? null) => "From {$data['from']}",
                        filled($data['until'] ?? null) => "Until {$data['until']}",
                        default => null,
                    }),
                Filter::make('request_id')
                    ->schema([TextInput::make('request_id')->label('Request id')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['request_id'] ?? null, fn (Builder $query, string $id) => $query->where('request_id', trim($id))))
                    ->indicateUsing(fn (array $data): ?string => filled($data['request_id'] ?? null) ? 'Request '.$data['request_id'] : null),
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
                        TextEntry::make('reason')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('ip_address')->placeholder('—'),
                        TextEntry::make('request_id')->label('Request')->placeholder('—'),
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
