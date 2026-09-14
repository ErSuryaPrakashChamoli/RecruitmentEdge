<?php

namespace App\Filament\Resources\RecruitmentRequisitions\RelationManagers;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\HierarchyService;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only pipeline for this requisition. Applications are created from the Applications /
 * Candidates resources (which enforce the Open-requisition guard) and moved only through
 * StageTransitionService, so no create/edit actions here. Rows are hierarchy-scoped by recruiter,
 * matching CandidateApplicationResource::getEloquentQuery().
 */
class ApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'applications';

    protected static ?string $title = 'Applications';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('application_code')
            ->modifyQueryUsing(function (Builder $query): Builder {
                /** @var User $user */
                $user = Filament::auth()->user();

                $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

                return $query
                    ->with(['candidate', 'recruiter'])
                    ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds));
            })
            ->columns([
                TextColumn::make('candidate.full_name')
                    ->label('Candidate')
                    ->searchable(),
                TextColumn::make('application_code')
                    ->label('Application')
                    ->searchable(),
                TextColumn::make('current_stage')
                    ->label('Stage')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStage $state) => $state->label())
                    ->color(fn (CandidateStage $state) => $state->color()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ApplicationStatus $state) => $state->label())
                    ->color(fn (ApplicationStatus $state) => $state->color()),
                TextColumn::make('recruiter.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn (CandidateApplication $record) => $record->recruiter?->fullName() ?? '—'),
            ])
            ->recordUrl(fn (CandidateApplication $record): string => CandidateApplicationResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
