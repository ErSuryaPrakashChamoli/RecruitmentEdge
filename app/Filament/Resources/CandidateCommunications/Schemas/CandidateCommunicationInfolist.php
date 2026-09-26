<?php

namespace App\Filament\Resources\CandidateCommunications\Schemas;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\CommunicationTrigger;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\CandidateCommunication;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Delivery timestamps show "Not reported" rather than guessing — only provider-reported events
 * are recorded (e.g. email opens are unknown unless a provider reports them).
 */
class CandidateCommunicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $reported = fn (string $field) => TextEntry::make($field)->dateTime()->placeholder('Not reported');

        return $schema
            ->columns(1)
            ->components([
                Section::make('Message')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('candidate.full_name')->label('Candidate')->url(fn (CandidateCommunication $record) => CandidateResource::getUrl('view', ['record' => $record->candidate_id])),
                        TextEntry::make('channel')->formatStateUsing(fn (CommunicationChannel $state) => $state->label()),
                        TextEntry::make('recipient'),
                        TextEntry::make('trigger')->formatStateUsing(fn (CommunicationTrigger $state) => $state->label()),
                        TextEntry::make('subject')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('body')->label('Message')->extraAttributes(['class' => 'whitespace-pre-line'])->columnSpanFull(),
                        TextEntry::make('template.name')->label('Template')->placeholder('Custom message')->suffix(fn (CandidateCommunication $record) => $record->template_version ? " (v{$record->template_version})" : ''),
                        TextEntry::make('candidateApplication.application_code')->label('Application')->placeholder('—'),
                        TextEntry::make('sentBy.first_name')->label('Sent by')->formatStateUsing(fn (CandidateCommunication $record) => $record->sentBy?->fullName())->placeholder('System'),
                    ]),
                Section::make('Delivery')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('status')->badge()->formatStateUsing(fn (CommunicationStatus $state) => $state->label())->color(fn (CommunicationStatus $state) => $state->color()),
                        TextEntry::make('provider')->placeholder('—'),
                        TextEntry::make('provider_message_id')->label('Provider message id')->placeholder('—'),
                        TextEntry::make('attempts'),
                        TextEntry::make('blocked_reason')->placeholder('—')->columnSpan(2),
                        TextEntry::make('error')->placeholder('—')->columnSpan(2),
                        $reported('queued_at'),
                        $reported('sent_at'),
                        $reported('delivered_at'),
                        $reported('read_at'),
                        $reported('opened_at'),
                        $reported('clicked_at'),
                        $reported('failed_at'),
                    ]),
            ]);
    }
}
