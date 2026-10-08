<?php

namespace App\Filament\Resources\HiringOutcomes;

use App\Enums\OutcomeCaptureMode;
use App\Enums\OutcomeCategory;
use App\Enums\OutcomeConfidence;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;
use App\Filament\Resources\HiringOutcomes\Pages\ListHiringOutcomes;
use App\Filament\Resources\HiringOutcomes\Pages\ViewHiringOutcome;
use App\Models\HiringOutcome;
use App\Models\RecruitmentRequisition;
use App\Models\User;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Outcome Loop™ (Phase 8.2): the recorded outcomes behind the Hiring Outcomes dashboard, with
 * provenance. Scoped like requisitions. Outcomes are never edited: a confirmation, correction or
 * void is a new audited version, and superseded versions stay visible.
 */
class HiringOutcomeResource extends Resource
{
    protected static ?string $model = HiringOutcome::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'EDGE Intelligence';

    protected static ?string $navigationLabel = 'Outcome Records';

    protected static ?string $modelLabel = 'outcome';

    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()->when(! $user->can('hierarchy.view-all'), fn (Builder $query) => $query->whereIn('requisition_id', RecruitmentRequisition::query()->visibleTo($user)->select('id')));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['requisition:id,code', 'candidateApplication:id,application_code']))
            ->defaultSort('observed_at', 'desc')
            ->columns([
                TextColumn::make('outcome_type')->label('Outcome')->formatStateUsing(fn (OutcomeType $state) => $state->label())->description(fn (HiringOutcome $record) => $record->category->label()),
                TextColumn::make('result')->badge()->formatStateUsing(fn (OutcomeResult $state) => $state->label())->color(fn (OutcomeResult $state) => $state->color()),
                TextColumn::make('value')->placeholder('—')->suffix(fn (HiringOutcome $record) => $record->unit !== null ? " {$record->unit}" : null),
                TextColumn::make('state')->badge()->formatStateUsing(fn (OutcomeState $state) => $state->label())->color(fn (OutcomeState $state) => $state->color()),
                TextColumn::make('confidence')->formatStateUsing(fn (OutcomeConfidence $state) => $state->label()),
                TextColumn::make('candidateApplication.application_code')->label('Application')->placeholder('—')->searchable(),
                TextColumn::make('requisition.code')->label('Requisition')->placeholder('—')->toggleable(),
                TextColumn::make('capture_mode')->label('Captured')->formatStateUsing(fn (OutcomeCaptureMode $state) => $state->label())->toggleable(),
                TextColumn::make('version')->prefix('v')->description(fn (HiringOutcome $record) => $record->is_current ? null : 'superseded'),
                TextColumn::make('observed_at')->label('Observed')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('outcome_type')->label('Outcome')->options(OutcomeType::options()),
                SelectFilter::make('category')->options(collect(OutcomeCategory::cases())->mapWithKeys(fn (OutcomeCategory $category) => [$category->value => $category->label()])->all()),
                SelectFilter::make('result')->options(OutcomeResult::options()),
                TernaryFilter::make('is_current')->label('Current versions only')->default(true),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No outcomes recorded yet')
            ->emptyStateDescription('Joining, offer, hiring-process and status-observation outcomes are recorded automatically as they happen and by the daily evaluation.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Outcome')->columns(3)->schema([
                TextEntry::make('outcome_type')->label('Outcome')->formatStateUsing(fn (OutcomeType $state) => $state->label())->helperText(fn (HiringOutcome $record) => $record->outcome_type->definition())->columnSpanFull(),
                TextEntry::make('result')->badge()->formatStateUsing(fn (OutcomeResult $state) => $state->label())->color(fn (OutcomeResult $state) => $state->color()),
                TextEntry::make('value')->placeholder('—')->suffix(fn (HiringOutcome $record) => $record->unit !== null ? " {$record->unit}" : null),
                TextEntry::make('state')->badge()->formatStateUsing(fn (OutcomeState $state) => $state->label())->color(fn (OutcomeState $state) => $state->color()),
                TextEntry::make('confidence')->formatStateUsing(fn (OutcomeConfidence $state) => $state->label()),
                TextEntry::make('observation_start')->label('Observation from')->date()->placeholder('—'),
                TextEntry::make('observation_end')->label('Observation to')->date()->placeholder('—'),
                TextEntry::make('candidateApplication.application_code')->label('Application')->placeholder('—'),
                TextEntry::make('requisition.code')->label('Requisition')->placeholder('—'),
                TextEntry::make('observed_at')->label('Observed')->dateTime(),
            ]),
            Section::make('Provenance')->columns(3)->schema([
                TextEntry::make('capture_mode')->label('Captured')->formatStateUsing(fn (OutcomeCaptureMode $state) => $state->label()),
                TextEntry::make('rule_version')->label('Rule version'),
                TextEntry::make('source_type')->label('Source record')->formatStateUsing(fn (?string $state, HiringOutcome $record) => $state !== null ? class_basename($state)." #{$record->source_id}" : '—')->placeholder('—'),
                TextEntry::make('version')->prefix('v')->helperText(fn (HiringOutcome $record) => $record->is_current ? 'Current version' : 'Superseded'),
                TextEntry::make('supersedes.version')->label('Replaces')->prefix('v')->placeholder('—'),
                TextEntry::make('correction_reason')->label('Reason')->placeholder('—'),
                KeyValueEntry::make('details')->columnSpanFull()->placeholder('—')->state(fn (HiringOutcome $record) => collect($record->details ?? [])->map(fn ($value) => is_scalar($value) || $value === null ? (string) ($value ?? '—') : json_encode($value))->all()),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHiringOutcomes::route('/'),
            'view' => ViewHiringOutcome::route('/{record}'),
        ];
    }
}
