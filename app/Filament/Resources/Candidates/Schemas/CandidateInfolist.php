<?php

namespace App\Filament\Resources\Candidates\Schemas;

use App\Models\Candidate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The candidate's 360 view. Their applications (one per requisition they've been put forward
 * for) and possible-duplicate matches live in the relation manager tabs already attached via
 * CandidateResource::getRelations() — not duplicated here.
 */
class CandidateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Overview')
                ->columns(3)
                ->schema([
                    TextEntry::make('candidate_code')
                        ->label('Code'),
                    TextEntry::make('full_name'),
                    TextEntry::make('source.name')
                        ->label('Source'),
                ]),
            Section::make('Contact')
                ->columns(3)
                ->schema([
                    TextEntry::make('mobile'),
                    TextEntry::make('alternate_mobile')
                        ->placeholder('—'),
                    TextEntry::make('email')
                        ->placeholder('—'),
                    TextEntry::make('location')
                        ->placeholder('—'),
                    TextEntry::make('current_city')
                        ->placeholder('—'),
                ]),
            Section::make('Professional')
                ->columns(3)
                ->schema([
                    TextEntry::make('qualification')
                        ->placeholder('—'),
                    TextEntry::make('current_company')
                        ->placeholder('—'),
                    TextEntry::make('current_designation')
                        ->placeholder('—'),
                    TextEntry::make('total_experience')
                        ->label('Total Experience (years)')
                        ->placeholder('—'),
                    TextEntry::make('relevant_experience')
                        ->label('Relevant Experience (years)')
                        ->placeholder('—'),
                    TextEntry::make('notice_period_days')
                        ->label('Notice Period (days)')
                        ->placeholder('—'),
                    TextEntry::make('current_salary')
                        ->money('INR')
                        ->placeholder('—'),
                    TextEntry::make('expected_salary')
                        ->money('INR')
                        ->placeholder('—'),
                ]),
            Section::make('Notes')
                ->schema([
                    TextEntry::make('remarks')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->visible(fn (Candidate $record) => filled($record->remarks)),
        ]);
    }
}
