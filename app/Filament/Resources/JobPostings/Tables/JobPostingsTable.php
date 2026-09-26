<?php

namespace App\Filament\Resources\JobPostings\Tables;

use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JobPostingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('requisition')->withCount(['applications', 'distributions as live_channels_count' => fn ($q) => $q->where('status', 'published')]))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->description(fn (JobPosting $record) => $record->requisition?->code),
                TextColumn::make('status')->badge()->formatStateUsing(fn (JobPostingStatus $state) => $state->label())->color(fn (JobPostingStatus $state) => $state->color()),
                TextColumn::make('live_channels_count')->label('Live channels'),
                TextColumn::make('applications_count')->label('Applications'),
                TextColumn::make('closes_at')->date()->placeholder('—'),
                TextColumn::make('published_at')->since()->placeholder('Never')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(JobPostingStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No job postings yet')
            ->emptyStateDescription('Create a posting for an Open requisition, then publish it to the career site and job boards.')
            ->emptyStateIcon('heroicon-o-megaphone');
    }
}
