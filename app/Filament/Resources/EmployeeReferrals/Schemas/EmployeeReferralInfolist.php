<?php

namespace App\Filament\Resources\EmployeeReferrals\Schemas;

use App\Enums\ReferralIncentiveStatus;
use App\Enums\ReferralRelationship;
use App\Enums\ReferralStatus;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Support\MasterDataLabel;
use App\Models\EmployeeReferral;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Candidate/application links only resolve for users allowed to open them — a referring employee
 * sees their referral's progress, not the candidate's internal record.
 */
class EmployeeReferralInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Referral')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('referral_code'),
                        TextEntry::make('status')->badge()->formatStateUsing(fn (ReferralStatus $state) => $state->label())->color(fn (ReferralStatus $state) => $state->color()),
                        TextEntry::make('referred_at')->date(),
                        TextEntry::make('referrer.first_name')->label('Referred by')->formatStateUsing(fn (EmployeeReferral $record) => $record->referrer?->fullName()),
                        TextEntry::make('candidate.full_name')
                            ->label('Candidate')
                            ->url(fn (EmployeeReferral $record): ?string => auth()->user()?->can('view', $record->candidate) ? CandidateResource::getUrl('view', ['record' => $record->candidate]) : null),
                        TextEntry::make('requisition.code')->label('Position')->placeholder('General referral'),
                        TextEntry::make('candidateApplication.application_code')
                            ->label('Application')
                            ->placeholder('Not yet in the pipeline')
                            ->url(fn (EmployeeReferral $record): ?string => $record->candidateApplication && auth()->user()?->can('view', $record->candidateApplication)
                                ? CandidateApplicationResource::getUrl('view', ['record' => $record->candidateApplication])
                                : null),
                        TextEntry::make('relationship')->formatStateUsing(fn (ReferralRelationship $state) => $state->label()),
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Outcome')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('reviewedBy.first_name')->label('Reviewed by')->formatStateUsing(fn (EmployeeReferral $record) => $record->reviewedBy?->fullName())->placeholder('—'),
                        TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                        TextEntry::make('rejectionReason.name')
                            ->formatStateUsing(MasterDataLabel::for('rejectionReason'))->label('Rejection reason')->placeholder('—'),
                        TextEntry::make('joining_date')->date()->placeholder('—'),
                        IconEntry::make('incentive_eligible')->label('Bonus eligible')->boolean(),
                        TextEntry::make('incentive_status')->label('Bonus')->badge()->formatStateUsing(fn (ReferralIncentiveStatus $state) => $state->label())->color(fn (ReferralIncentiveStatus $state) => $state->color()),
                        TextEntry::make('incentiveCalculation.status')->label('Payout status')->formatStateUsing(fn ($state) => $state?->label())->placeholder('—'),
                    ]),
            ]);
    }
}
