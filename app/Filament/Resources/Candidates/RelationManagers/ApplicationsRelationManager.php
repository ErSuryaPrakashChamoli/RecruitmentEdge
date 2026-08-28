<?php

namespace App\Filament\Resources\Candidates\RelationManagers;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\Priority;
use App\Models\Employee;
use App\Services\SequenceCodeGenerator;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'applications';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('requisition_id')
                    ->relationship('requisition', 'code')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('recruiter_id')
                    ->relationship('recruiter', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('priority')
                    ->options(collect(Priority::cases())->mapWithKeys(fn (Priority $p) => [$p->value => $p->label()]))
                    ->default(Priority::Medium)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('application_code')
            ->columns([
                TextColumn::make('application_code')
                    ->label('Application'),
                TextColumn::make('requisition.code')
                    ->label('Requisition'),
                TextColumn::make('current_stage')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStage $state) => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ApplicationStatus $state) => $state->label()),
                TextColumn::make('recruiter.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn ($record) => $record->recruiter->fullName()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['application_code'] = app(SequenceCodeGenerator::class)->next('APP');
                        $data['current_stage'] = CandidateStage::Sourced;
                        $data['status'] = ApplicationStatus::Active;
                        $data['application_date'] = now();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
