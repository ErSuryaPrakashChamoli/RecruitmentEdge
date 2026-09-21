<?php

namespace App\Filament\Resources\Interviewers;

use App\Filament\Resources\Interviewers\Pages\ManageInterviewers;
use App\Models\Employee;
use App\Models\Interviewer;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
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
                    ->label('Designation')
                    ->placeholder('—'),
                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageInterviewers::route('/'),
        ];
    }
}
