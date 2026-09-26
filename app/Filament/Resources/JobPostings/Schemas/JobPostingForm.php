<?php

namespace App\Filament\Resources\JobPostings\Schemas;

use App\Enums\RequisitionStatus;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\RecruitmentRequisition;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JobPostingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Posting')
                    ->columns(2)
                    ->schema([
                        Select::make('requisition_id')
                            ->label('Requisition')
                            ->options(fn (): array => RecruitmentRequisitionResource::getEloquentQuery()
                                ->where('status', RequisitionStatus::Open)
                                ->whereDoesntHave('jobPosting')
                                ->with('designation')
                                ->get()
                                ->mapWithKeys(fn (RecruitmentRequisition $r) => [$r->id => "{$r->code} — {$r->designation?->name}"])
                                ->all())
                            ->helperText('Only Open (approved) requisitions without a posting are listed.')
                            ->searchable()
                            ->required()
                            ->visibleOn('create'),
                        TextInput::make('title')->required()->maxLength(255),
                        DatePicker::make('closes_at')->label('Closing date')->minDate(today()),
                        Toggle::make('show_salary')->label('Show the salary range publicly')->inline(false),
                        Textarea::make('summary')->rows(2)->maxLength(500)->columnSpanFull(),
                        Textarea::make('description')
                            ->label('Public description')
                            ->helperText('Shown on the career site and job boards. Do not include internal notes.')
                            ->rows(12)
                            ->required()
                            ->minLength(50)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
