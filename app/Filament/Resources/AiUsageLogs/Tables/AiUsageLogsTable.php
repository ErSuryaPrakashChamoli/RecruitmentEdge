<?php

namespace App\Filament\Resources\AiUsageLogs\Tables;

use App\Models\AiUsageLog;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Column summaries (requests, tokens, cost) are computed over the whole filtered query — not just
 * the current page — so narrowing the date range filter gives that range's totals.
 */
class AiUsageLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('—'),
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('model'),
                TextColumn::make('request_type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? $state)
                    ->summarize(Count::make('requests')->label('Requests')),
                TextColumn::make('input_tokens')
                    ->label('In')
                    ->numeric()
                    ->summarize(Sum::make('input_tokens')->label('Input tokens')->numeric()),
                TextColumn::make('output_tokens')
                    ->label('Out')
                    ->numeric()
                    ->summarize(Sum::make('output_tokens')->label('Output tokens')->numeric()),
                TextColumn::make('cached_tokens')
                    ->label('Cached')
                    ->numeric()
                    ->summarize(Sum::make('cached_tokens')->label('Cached tokens')->numeric()),
                TextColumn::make('cost')
                    ->label('Cost (USD)')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 6))
                    ->placeholder('—')
                    ->sortable()
                    ->summarize(
                        Sum::make('total_cost')
                            ->label('Total cost')
                            ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 4)),
                    ),
                TextColumn::make('latency_ms')
                    ->label('Latency (ms)'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state === 'success' ? 'success' : 'danger'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('created_at')
                    ->label('Date range')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('created_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->whereDate('created_at', '<=', $until)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'From '.$data['from'];
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Until '.$data['until'];
                        }

                        return $indicators;
                    }),
                SelectFilter::make('request_type')
                    ->options([
                        'chat' => 'Chat',
                        'embedding' => 'Embedding',
                        'tool_call' => 'Tool Call',
                        'web_search' => 'Web Search',
                    ]),
                SelectFilter::make('provider')
                    ->options(fn (): array => AiUsageLog::query()->distinct()->orderBy('provider')->pluck('provider', 'provider')->all()),
                SelectFilter::make('status')
                    ->options(['success' => 'Success', 'error' => 'Error']),
            ]);
    }
}
