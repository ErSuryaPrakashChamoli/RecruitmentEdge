<?php

namespace App\Filament\Resources\Candidates\Schemas;

use App\Filament\Resources\Candidates\Pages\CreateCandidate;
use App\Filament\Support\ActiveMasterDataOptions;
use App\Models\Employee;
use App\Services\Tenancy\TenantStorage;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CandidateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basic Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('candidate_code')
                            ->disabled()
                            ->dehydrated(false)
                            ->hidden(fn (string $operation): bool => $operation === 'create'),
                        TextInput::make('full_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('mobile')
                            ->tel()
                            ->required()
                            ->maxLength(20),
                        TextInput::make('alternate_mobile')
                            ->tel()
                            ->maxLength(20),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('location')
                            ->maxLength(255),
                        TextInput::make('current_city')
                            ->maxLength(255),
                    ]),
                Section::make('Professional Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('qualification')
                            ->maxLength(255),
                        TextInput::make('current_company')
                            ->maxLength(255),
                        TextInput::make('current_designation')
                            ->maxLength(255),
                        TextInput::make('total_experience')
                            ->label('Total Experience (years)')
                            ->numeric(),
                        TextInput::make('relevant_experience')
                            ->label('Relevant Experience (years)')
                            ->numeric(),
                        TextInput::make('current_salary')
                            ->numeric(),
                        TextInput::make('expected_salary')
                            ->numeric(),
                        Select::make('notice_period_days')
                            ->label('Notice Period')
                            ->options([
                                0 => 'Immediate',
                                15 => '15 days',
                                30 => '30 days',
                                60 => '60 days',
                                90 => '90 days',
                            ]),
                        TextInput::make('skills')
                            ->label('Skills (comma separated)')
                            ->dehydrateStateUsing(fn (?string $state): array => collect(explode(',', (string) $state))->map(trim(...))->filter()->all())
                            ->formatStateUsing(fn (?array $state): string => implode(', ', $state ?? []))
                            ->columnSpanFull(),
                        FileUpload::make('resume_path')
                            ->label('Resume')
                            ->disk('local')
                            ->visibility('private')
                            ->directory(fn (): string => TenantStorage::path('resumes'))
                            ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                            ->maxSize(5120)
                            ->columnSpanFull(),
                    ]),
                Section::make('Source')
                    ->columns(2)
                    ->schema([
                        Select::make('source_id')
                            ->relationship('source', 'name', ActiveMasterDataOptions::scope('source_id'))
                            ->required()
                            ->searchable()
                            ->preload(),
                        TextInput::make('source_details')
                            ->maxLength(255),
                        Select::make('referral_employee_id')
                            ->label('Referral Employee')
                            ->relationship('referralEmployee', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->searchable()
                            ->preload(),
                    ]),
                Textarea::make('remarks')
                    ->columnSpanFull(),
                Section::make('Possible duplicate candidate')
                    ->description('This candidate matches an existing record. Open the existing candidate and add an application there instead of creating a second record.')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('warning')
                    ->columnSpanFull()
                    ->visible(fn ($livewire): bool => $livewire instanceof CreateCandidate && $livewire->duplicateMatches !== [])
                    ->schema([
                        TextEntry::make('duplicate_matches')
                            ->hiddenLabel()
                            ->state(fn ($livewire): array => collect($livewire->duplicateMatches)
                                ->map(fn (array $match): string => "{$match['name']} ({$match['candidate_code']}) · Mobile {$match['mobile']} · Email {$match['email']} · {$match['match']}, {$match['confidence']}% confidence")
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted(),
                        Textarea::make('duplicate_override_reason')
                            ->label('Justification for creating a new candidate anyway')
                            ->helperText('Recorded in the audit log.')
                            ->visible(fn (): bool => (bool) auth()->user()?->can('candidates.override-duplicate'))
                            ->maxLength(1000),
                    ]),
            ]);
    }
}
