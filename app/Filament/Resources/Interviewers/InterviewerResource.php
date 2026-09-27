<?php

namespace App\Filament\Resources\Interviewers;

use App\Filament\Resources\Interviewers\Pages\ManageInterviewers;
use App\Filament\Support\MasterDataLabel;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Interviewer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class InterviewerResource extends Resource
{
    protected static ?string $model = Interviewer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('employee.designation');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('Employee')
                    ->options(fn (): array => Employee::query()
                        ->with('designation')
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Employee $employee): array => [$employee->id => Interviewer::optionLabel($employee)])
                        ->all())
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'This employee is already on the interviewer list.'])
                    ->searchable()
                    ->required(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.employee_code')
                    ->label('Emp ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('employee.first_name')
                    ->label('Name')
                    ->formatStateUsing(fn (Interviewer $record): string => $record->employee->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('employee.designation.name')
                    ->formatStateUsing(MasterDataLabel::for('employee.designation'))
                    ->label('Designation')
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            // Phase 8.6 (D8.6-009): deactivate (with a reason) instead of delete; no bulk actions.
            ->recordActions([
                Action::make('deactivateInterviewer')
                    ->label('Deactivate')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('They stay on their existing interviews but can no longer be given new ones.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(1000)])
                    ->visible(fn (Interviewer $record): bool => $record->is_active && (bool) auth()->user()?->can('update', $record))
                    ->action(function (Interviewer $record, array $data): void {
                        AuditLog::withReason($data['reason'], fn () => $record->update(['is_active' => false]));
                        Notification::make()->title('Interviewer deactivated')->success()->send();
                    }),
                Action::make('activateInterviewer')
                    ->label('Activate')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Interviewer $record): bool => ! $record->is_active && (bool) auth()->user()?->can('update', $record))
                    ->action(function (Interviewer $record): void {
                        $record->update(['is_active' => true]);
                        Notification::make()->title('Interviewer activated')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageInterviewers::route('/'),
        ];
    }
}
