<?php

namespace App\Filament\Resources\CandidateCommunications\Tables;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\CommunicationTrigger;
use App\Models\CandidateCommunication;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CandidateCommunicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['candidate:id,full_name,candidate_code', 'candidateApplication:id,application_code', 'sentBy:id,first_name,last_name']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('candidate.full_name')->label('Candidate')->searchable(),
                TextColumn::make('channel')->badge()->color('gray')->formatStateUsing(fn (CommunicationChannel $state) => $state->label()),
                TextColumn::make('subject')
                    ->label('Message')
                    ->state(fn (CandidateCommunication $record): string => $record->subject ?? $record->body)
                    ->limit(60)
                    ->searchable(['subject', 'body']),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CommunicationStatus $state) => $state->label())
                    ->color(fn (CommunicationStatus $state) => $state->color())
                    ->description(fn (CandidateCommunication $record): ?string => $record->blocked_reason ?? ($record->status === CommunicationStatus::Failed ? $record->error : null)),
                TextColumn::make('trigger')->formatStateUsing(fn (CommunicationTrigger $state) => $state->label())->toggleable(),
                TextColumn::make('candidateApplication.application_code')->label('Application')->placeholder('—')->toggleable(),
                TextColumn::make('sentBy.first_name')->label('Sent by')->formatStateUsing(fn (CandidateCommunication $record) => $record->sentBy?->fullName())->placeholder('System')->toggleable(),
                TextColumn::make('created_at')->label('When')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')->options(CommunicationChannel::options(sendableOnly: true)),
                SelectFilter::make('status')->options(CommunicationStatus::options())->multiple(),
                SelectFilter::make('trigger')->options(collect(CommunicationTrigger::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No messages yet')
            ->emptyStateDescription('Messages sent to candidates — manually or automatically — appear here with their delivery status.')
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right');
    }
}
