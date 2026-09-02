<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('employee_code')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('mobile')
                    ->tel()
                    ->maxLength(20),
                Select::make('department_id')
                    ->relationship('department', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('designation_id')
                    ->relationship('designation', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('reports_to_id')
                    ->label('Reports To')
                    ->relationship(name: 'reportsTo', titleAttribute: 'first_name', ignoreRecord: true)
                    ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName().' ('.$record->employee_code.')')
                    ->searchable()
                    ->preload(),
                DatePicker::make('date_of_joining'),
                Select::make('status')
                    ->options(self::statusOptions())
                    ->default(EmployeeStatus::Active)
                    ->required(),
                TextInput::make('category')
                    ->maxLength(255),
                TextInput::make('level')
                    ->maxLength(255),
                FileUpload::make('photo_path')
                    ->label('Photo')
                    ->image()
                    ->avatar()
                    ->disk('public')
                    ->directory('employee-photos'),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(EmployeeStatus::cases())
            ->mapWithKeys(fn (EmployeeStatus $status) => [$status->value => $status->label()])
            ->all();
    }
}
