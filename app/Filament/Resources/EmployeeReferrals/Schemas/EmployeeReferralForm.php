<?php

namespace App\Filament\Resources\EmployeeReferrals\Schemas;

use App\Enums\ReferralRelationship;
use App\Enums\RequisitionStatus;
use App\Filament\Resources\EmployeeReferrals\Pages\CreateEmployeeReferral;
use App\Models\RecruitmentRequisition;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EmployeeReferralForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Position')
                    ->visibleOn('create')
                    ->schema([
                        Select::make('requisition_id')
                            ->label('Open position')
                            ->helperText('Leave empty for a general referral — recruiters will match the candidate to a position.')
                            ->options(fn (): array => self::openPositions())
                            ->searchable(),
                    ]),
                Section::make('Candidate')
                    ->visibleOn('create')
                    ->columns(2)
                    ->schema([
                        TextInput::make('full_name')->required()->maxLength(255),
                        TextInput::make('mobile')->tel()->required()->maxLength(20),
                        TextInput::make('email')->email()->maxLength(255),
                        TextInput::make('current_city')->maxLength(255),
                        TextInput::make('current_company')->maxLength(255),
                        TextInput::make('current_designation')->maxLength(255),
                        TextInput::make('total_experience')->label('Total experience (years)')->numeric()->minValue(0)->maxValue(60),
                        FileUpload::make('resume_path')
                            ->label('Resume')
                            ->disk('local')
                            ->visibility('private')
                            ->directory('resumes')
                            ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                            ->maxSize(5120),
                    ]),
                Section::make('Candidate already in our system')
                    ->description('We found an existing candidate matching these details. Refer the existing candidate instead of creating a duplicate.')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('warning')
                    ->visible(fn ($livewire): bool => $livewire instanceof CreateEmployeeReferral && $livewire->duplicateMatches !== [])
                    ->schema([
                        Radio::make('existing_candidate_choice')
                            ->hiddenLabel()
                            // Array union, not spread: spreading renumbers the integer candidate-id keys.
                            ->options(fn ($livewire): array => collect($livewire->duplicateMatches)
                                ->mapWithKeys(fn (array $match) => [
                                    $match['id'] => "Refer {$match['name']} ({$match['candidate_code']}) · Mobile {$match['mobile']} · Email {$match['email']}",
                                ])
                                ->all() + (auth()->user()?->can('candidates.override-duplicate') ? ['new' => 'This is a different person — create a new candidate'] : []))
                            ->live()
                            ->required(),
                        Textarea::make('duplicate_override_reason')
                            ->label('Why is this a different person?')
                            ->visible(fn (Get $get): bool => $get('existing_candidate_choice') === 'new')
                            ->required(fn (Get $get): bool => $get('existing_candidate_choice') === 'new')
                            ->maxLength(1000),
                    ]),
                Section::make('Referral')
                    ->columns(2)
                    ->schema([
                        Select::make('relationship')
                            ->label('How do you know them?')
                            ->options(ReferralRelationship::options())
                            ->required(),
                        Textarea::make('notes')
                            ->label('Why are they a good fit?')
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Every Open requisition, for any employee — an internal job board, not the hierarchy-scoped
     * requisition list (employees refer for positions outside their own reporting line).
     *
     * @return array<int, string>
     */
    public static function openPositions(): array
    {
        return RecruitmentRequisition::query()
            ->where('status', RequisitionStatus::Open)
            ->with(['designation:id,name', 'location:id,name'])
            ->orderByDesc('opening_date')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (RecruitmentRequisition $r) => [$r->id => "{$r->code} — {$r->designation?->name} · {$r->location?->name}"])
            ->all();
    }
}
