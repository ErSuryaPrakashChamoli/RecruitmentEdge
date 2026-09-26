<?php

namespace App\Filament\Resources\HiringMemoryRecords;

use App\Enums\IntelligenceAiStatus;
use App\Enums\MemoryType;
use App\Filament\Resources\HiringMemoryRecords\Pages\ListHiringMemoryRecords;
use App\Filament\Resources\HiringMemoryRecords\Pages\ViewHiringMemoryRecord;
use App\Models\HiringMemoryRecord;
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
 * Hiring Memory™ (Phase 7): immutable records of what happened — hires, rejections, offers not
 * converted, failed joinings, requisition outcomes — scoped like requisitions. Corrections create a
 * new version; the superseded one stays visible.
 */
class HiringMemoryRecordResource extends Resource
{
    protected static ?string $model = HiringMemoryRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'EDGE Intelligence';

    protected static ?string $navigationLabel = 'Hiring Memory';

    protected static ?string $modelLabel = 'memory record';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()->when(! $user->can('hierarchy.view-all'), fn (Builder $query) => $query->whereIn('requisition_id', RecruitmentRequisition::query()->visibleTo($user)->select('id')));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['requisition:id,code', 'designation:id,name']))
            ->defaultSort('captured_at', 'desc')
            ->columns([
                TextColumn::make('memory_type')->label('Type')->badge()->formatStateUsing(fn (MemoryType $state) => $state->label())->color(fn (MemoryType $state) => $state->color()),
                TextColumn::make('summary')->wrap()->searchable(),
                TextColumn::make('requisition.code')->label('Requisition')->placeholder('—'),
                TextColumn::make('designation.name')->label('Designation')->placeholder('—')->toggleable(),
                TextColumn::make('version')->prefix('v')->description(fn (HiringMemoryRecord $record) => $record->is_current ? null : 'superseded'),
                TextColumn::make('captured_at')->label('Captured')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('memory_type')->label('Type')->options(MemoryType::options()),
                SelectFilter::make('designation')->relationship('designation', 'name')->searchable(),
                TernaryFilter::make('is_current')->label('Current versions only')->default(true),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No hiring memory yet')
            ->emptyStateDescription('Hires, rejections, declined offers, failed joinings and requisition outcomes are recorded automatically as they happen.')
            ->emptyStateIcon('heroicon-o-archive-box');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('What happened')->columns(3)->schema([
                TextEntry::make('memory_type')->label('Type')->badge()->formatStateUsing(fn (MemoryType $state) => $state->label())->color(fn (MemoryType $state) => $state->color()),
                TextEntry::make('captured_at')->label('Captured')->dateTime(),
                TextEntry::make('source_event')->label('Captured from'),
                TextEntry::make('summary')->columnSpanFull(),
                TextEntry::make('version')->prefix('v')->helperText(fn (HiringMemoryRecord $record) => $record->is_current ? 'Current version' : 'Superseded by a correction'),
                TextEntry::make('supersedes.version')->label('Corrects')->prefix('v')->placeholder('—'),
                TextEntry::make('correction_reason')->placeholder('—'),
            ]),
            Section::make('Recorded facts')->description('Captured deterministically at the time; never recalculated.')->schema([
                KeyValueEntry::make('facts')->hiddenLabel()->state(fn (HiringMemoryRecord $record) => collect($record->facts)->map(fn ($value) => is_scalar($value) || $value === null ? (string) ($value ?? '—') : json_encode($value))->all()),
            ]),
            Section::make('AI summary')->description('Optional. Written by AI from the facts above only — labelled, never used as a fact.')->schema([
                TextEntry::make('ai_status')->label('Status')->badge()->formatStateUsing(fn (IntelligenceAiStatus $state) => $state->label())->color(fn (IntelligenceAiStatus $state) => $state->color()),
                TextEntry::make('ai_summary')->hiddenLabel()->placeholder('No AI summary.')->helperText(fn (HiringMemoryRecord $record) => $record->ai_model !== null ? "AI-generated by {$record->ai_model} on {$record->ai_generated_at?->toDayDateTimeString()}." : null),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHiringMemoryRecords::route('/'),
            'view' => ViewHiringMemoryRecord::route('/{record}'),
        ];
    }
}
