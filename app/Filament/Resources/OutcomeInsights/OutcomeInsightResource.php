<?php

namespace App\Filament\Resources\OutcomeInsights;

use App\Enums\IntelligenceAiStatus;
use App\Enums\OutcomeConfidence;
use App\Enums\OutcomeInsightKind;
use App\Enums\OutcomeInsightStatus;
use App\Enums\OutcomeSampleBand;
use App\Filament\Resources\OutcomeInsights\Pages\ListOutcomeInsights;
use App\Filament\Resources\OutcomeInsights\Pages\ViewOutcomeInsight;
use App\Models\OutcomeInsight;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Outcome Loop™ (Phase 8.2): learning insights awaiting a human decision. Each is an observational
 * aggregate with its evidence, sample size, period, confidence and limitations; nothing changes
 * until a reviewer accepts it.
 */
class OutcomeInsightResource extends Resource
{
    protected static ?string $model = OutcomeInsight::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'EDGE Intelligence';

    protected static ?string $navigationLabel = 'Outcome Insights';

    protected static ?string $modelLabel = 'outcome insight';

    protected static ?int $navigationSort = 6;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('designation:id,name'))
            ->defaultSort('last_recalculated_at', 'desc')
            ->columns([
                TextColumn::make('status')->badge()->formatStateUsing(fn (OutcomeInsightStatus $state) => $state->label())->color(fn (OutcomeInsightStatus $state) => $state->color()),
                TextColumn::make('kind')->formatStateUsing(fn (OutcomeInsightKind $state) => $state->label())->description(fn (OutcomeInsight $record) => $record->designation?->name),
                TextColumn::make('insight')->wrap()->limit(160),
                TextColumn::make('sample_size')->label('Sample')->description(fn (OutcomeInsight $record) => $record->sample_band->label()),
                TextColumn::make('confidence')->formatStateUsing(fn (OutcomeConfidence $state) => $state->label()),
                TextColumn::make('last_recalculated_at')->label('Recalculated')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(OutcomeInsightStatus::cases())->mapWithKeys(fn (OutcomeInsightStatus $status) => [$status->value => $status->label()])->all())->default(OutcomeInsightStatus::Review->value),
                SelectFilter::make('kind')->options(collect(OutcomeInsightKind::cases())->mapWithKeys(fn (OutcomeInsightKind $kind) => [$kind->value => $kind->label()])->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No insights to review')
            ->emptyStateDescription('Insights appear once at least '.config('outcomes.sample.insufficient_below', 3).' comparable outcomes have been observed. They are recalculated daily.')
            ->emptyStateIcon('heroicon-o-light-bulb');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Insight')->description('Observational — describes what happened among past hires, not why. Never a reason to exclude anyone.')->columns(3)->schema([
                TextEntry::make('insight')->hiddenLabel()->columnSpanFull(),
                TextEntry::make('suggested_change')->label('Suggested')->columnSpanFull()->placeholder('—'),
                TextEntry::make('kind')->formatStateUsing(fn (OutcomeInsightKind $state) => $state->label()),
                TextEntry::make('designation.name')->label('Designation')->placeholder('All designations'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (OutcomeInsightStatus $state) => $state->label())->color(fn (OutcomeInsightStatus $state) => $state->color()),
            ]),
            Section::make('Basis')->columns(3)->schema([
                TextEntry::make('sample_size')->label('Sample size'),
                TextEntry::make('sample_band')->label('History')->badge()->formatStateUsing(fn (OutcomeSampleBand $state) => $state->label())->color(fn (OutcomeSampleBand $state) => $state->color()),
                TextEntry::make('confidence')->formatStateUsing(fn (OutcomeConfidence $state) => $state->label()),
                TextEntry::make('period_start')->label('Period from')->date()->placeholder('—'),
                TextEntry::make('period_end')->label('Period to')->date()->placeholder('—'),
                TextEntry::make('rule_version')->label('Rule version'),
                TextEntry::make('limitations')->columnSpanFull(),
                KeyValueEntry::make('evidence')->label('Counts')->columnSpanFull()->state(fn (OutcomeInsight $record) => collect($record->evidence)->map(fn ($value) => is_scalar($value) || $value === null ? (string) ($value ?? '—') : json_encode($value))->all()),
                TextEntry::make('source_refs')->label('Sources')->columnSpanFull()->state(fn (OutcomeInsight $record) => collect($record->source_refs)->map(fn ($value, $key) => $key.': '.(is_array($value) ? count($value).' record(s)' : $value))->implode(' · ')),
            ]),
            Section::make('AI explanation')->description('Optional. Written by AI from the aggregate figures above only — labelled, never used as evidence or as a decision.')->schema([
                TextEntry::make('ai_status')->label('Status')->badge()->formatStateUsing(fn (?IntelligenceAiStatus $state) => ($state ?? IntelligenceAiStatus::NotRequested)->label())->color(fn (?IntelligenceAiStatus $state) => ($state ?? IntelligenceAiStatus::NotRequested)->color()),
                TextEntry::make('ai_summary')->hiddenLabel()->placeholder('No AI explanation.')->helperText(fn (OutcomeInsight $record) => $record->ai_model !== null ? "AI-generated by {$record->ai_model} on {$record->ai_generated_at?->toDayDateTimeString()}." : null),
            ]),
            Section::make('Decision')->columns(3)->schema([
                TextEntry::make('reviewer.name')->label('Decided by')->placeholder('Awaiting review'),
                TextEntry::make('reviewed_at')->label('Decided')->dateTime()->placeholder('—'),
                TextEntry::make('applied_ref')->label('Applied as')->placeholder('—'),
                TextEntry::make('review_reason')->label('Note')->columnSpanFull()->placeholder('—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutcomeInsights::route('/'),
            'view' => ViewOutcomeInsight::route('/{record}'),
        ];
    }
}
