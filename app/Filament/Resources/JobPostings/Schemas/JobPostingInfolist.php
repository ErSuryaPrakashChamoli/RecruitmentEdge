<?php

namespace App\Filament\Resources\JobPostings\Schemas;

use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JobPostingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Posting')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('title'),
                        TextEntry::make('requisition.code')->label('Requisition'),
                        TextEntry::make('status')->badge()->formatStateUsing(fn (JobPostingStatus $state) => $state->label())->color(fn (JobPostingStatus $state) => $state->color()),
                        TextEntry::make('closes_at')->date()->placeholder('Open until filled'),
                        TextEntry::make('public_url')->label('Career site link')->state(fn (JobPosting $record) => route('careers.show', $record->public_slug))->url(fn (JobPosting $record) => route('careers.show', $record->public_slug), shouldOpenInNewTab: true)->columnSpan(2),
                        IconEntry::make('show_salary')->boolean(),
                        TextEntry::make('applications_count')->label('Online applications')->state(fn (JobPosting $record) => $record->applications()->count()),
                        TextEntry::make('summary')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('description')->extraAttributes(['class' => 'whitespace-pre-line'])->columnSpanFull(),
                    ]),
            ]);
    }
}
