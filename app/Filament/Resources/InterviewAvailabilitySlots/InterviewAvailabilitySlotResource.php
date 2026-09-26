<?php

namespace App\Filament\Resources\InterviewAvailabilitySlots;

use App\Filament\Resources\InterviewAvailabilitySlots\Pages\CreateInterviewAvailabilitySlot;
use App\Filament\Resources\InterviewAvailabilitySlots\Pages\ListInterviewAvailabilitySlots;
use App\Filament\Resources\InterviewAvailabilitySlots\Pages\ViewInterviewAvailabilitySlot;
use App\Filament\Resources\InterviewAvailabilitySlots\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\InterviewAvailabilitySlots\Schemas\InterviewAvailabilitySlotForm;
use App\Filament\Resources\InterviewAvailabilitySlots\Schemas\InterviewAvailabilitySlotInfolist;
use App\Filament\Resources\InterviewAvailabilitySlots\Tables\InterviewAvailabilitySlotsTable;
use App\Models\InterviewAvailabilitySlot;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Interviewer availability for candidate self-scheduling (Phase 4). No edit page on purpose: a
 * published slot may already be booked, so it is withdrawn (Cancel) and republished instead.
 */
class InterviewAvailabilitySlotResource extends Resource
{
    protected static ?string $model = InterviewAvailabilitySlot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Candidate Experience';

    protected static ?string $navigationLabel = 'Interview Slots';

    protected static ?string $modelLabel = 'interview slot';

    public static function form(Schema $schema): Schema
    {
        return InterviewAvailabilitySlotForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InterviewAvailabilitySlotInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InterviewAvailabilitySlotsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        if ($visibleIds === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereIn('interviewer_id', $visibleIds)->orWhereIn('created_by', $visibleIds));
    }

    public static function getRelations(): array
    {
        return [
            BookingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInterviewAvailabilitySlots::route('/'),
            'create' => CreateInterviewAvailabilitySlot::route('/create'),
            'view' => ViewInterviewAvailabilitySlot::route('/{record}'),
        ];
    }
}
