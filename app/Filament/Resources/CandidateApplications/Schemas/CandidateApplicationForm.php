<?php

namespace App\Filament\Resources\CandidateApplications\Schemas;

use App\Enums\Priority;
use App\Filament\Resources\Candidates\Schemas\CandidatePicker;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\Employee;
use App\Services\RecruitmentActivityService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CandidateApplicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('application_code')
                            ->disabled()
                            ->dehydrated(false)
                            ->hidden(fn (string $operation): bool => $operation === 'create'),
                        DatePicker::make('application_date')
                            ->default(now())
                            ->required(),
                        CandidatePicker::make()
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation !== 'create'),
                        Select::make('requisition_id')
                            ->relationship(
                                'requisition',
                                'code',
                                modifyQueryUsing: fn (Builder $query, string $operation): Builder => $operation === 'create'
                                    ? RecruitmentRequisitionResource::applicationTargetQuery($query)
                                    : $query,
                            )
                            ->helperText(fn (string $operation): ?string => $operation === 'create' ? RecruitmentRequisitionResource::applicationTargetHelperText() : 'Use "Move to requisition" to change it.')
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->searchable()
                            ->preload(),
                    ]),
                Section::make('Assignment & Priority')
                    ->columns(2)
                    ->schema([
                        // Phase 8.10 (P810-SEC-004): yourself or your team; CreateCandidateApplication re-checks.
                        Select::make('recruiter_id')
                            ->label('Recruiter')
                            ->relationship('recruiter', 'first_name', modifyQueryUsing: fn (Builder $query): Builder => $query->whereIn('employees.id', app(RecruitmentActivityService::class)->recruitersFor(Filament::auth()->user())->select('id')))
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->helperText(fn (string $operation): ?string => $operation === 'create' ? null : 'Use "Reassign recruiter" to change it.')
                            ->searchable()
                            ->preload(),
                        Select::make('priority')
                            ->options(collect(Priority::cases())->mapWithKeys(fn (Priority $p) => [$p->value => $p->label()]))
                            ->default(Priority::Medium)
                            ->required(),
                    ]),
                Section::make('Follow-up')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('next_followup_at'),
                        Textarea::make('remarks')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
