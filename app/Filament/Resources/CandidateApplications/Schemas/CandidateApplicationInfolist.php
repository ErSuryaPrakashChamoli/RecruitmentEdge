<?php

namespace App\Filament\Resources\CandidateApplications\Schemas;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\Priority;
use App\Models\CandidateApplication;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The application's 360 view — candidate, position, recruiter, and where it stands right now.
 * The full journey (every stage transition with who/when) lives in the Stage History relation
 * manager tab, not duplicated here.
 */
class CandidateApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Overview')
                ->columns(3)
                ->schema([
                    TextEntry::make('candidate.full_name')
                        ->label('Candidate'),
                    TextEntry::make('application_code')
                        ->label('Application'),
                    TextEntry::make('requisition.code')
                        ->label('Requisition'),
                    TextEntry::make('recruiter.first_name')
                        ->label('Recruiter')
                        ->formatStateUsing(fn (CandidateApplication $record) => $record->recruiter->fullName()),
                    TextEntry::make('current_stage')
                        ->badge()
                        ->formatStateUsing(fn (CandidateStage $state) => $state->label())
                        ->color(fn (CandidateStage $state) => $state->color()),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (ApplicationStatus $state) => $state->label())
                        ->color(fn (ApplicationStatus $state) => $state->color()),
                    TextEntry::make('priority')
                        ->badge()
                        ->formatStateUsing(fn (Priority $state) => $state->label())
                        ->color(fn (Priority $state) => $state->color()),
                    TextEntry::make('application_date')
                        ->date(),
                    TextEntry::make('last_activity_at')
                        ->label('Last Activity')
                        ->dateTime()
                        ->placeholder('—'),
                ]),
            Section::make('Follow-up & Notes')
                ->columns(2)
                ->schema([
                    TextEntry::make('next_followup_at')
                        ->label('Next Follow-up')
                        ->dateTime()
                        ->placeholder('None scheduled'),
                    TextEntry::make('remarks')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
