<?php

namespace App\Filament\Resources\RecruitmentCampaigns\Schemas;

use App\Enums\CampaignStatus;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Services\HierarchyService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RecruitmentCampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Campaign')
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('code')
                            ->label('Tracking code')
                            ->helperText('Used in links: /careers/{job}?campaign=CODE. Generated when left blank.')
                            ->maxLength(40)
                            ->alphaDash()
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        Select::make('status')->options(CampaignStatus::options())->default(CampaignStatus::Draft->value)->required(),
                        Select::make('owner_id')
                            ->label('Owner')
                            ->options(function (): array {
                                $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor(auth()->user());

                                return Employee::query()->when($visibleIds !== null, fn ($q) => $q->whereKey($visibleIds))->orderBy('first_name')->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])->all();
                            })
                            ->default(fn () => auth()->user()?->employee_id)
                            ->searchable()
                            ->required(),
                        DatePicker::make('starts_on'),
                        DatePicker::make('ends_on')->afterOrEqual('starts_on'),
                        TextInput::make('budget')->numeric()->minValue(0)->prefix('₹'),
                        TextInput::make('target_hires')->numeric()->integer()->minValue(0),
                        Textarea::make('description')->columnSpanFull(),
                    ]),
                Section::make('Linked records')
                    ->columns(2)
                    ->schema([
                        Select::make('requisition_ids')
                            ->label('Requisitions')
                            ->multiple()
                            ->options(fn (): array => RecruitmentRequisitionResource::getEloquentQuery()->with('designation')->get()->mapWithKeys(fn (RecruitmentRequisition $r) => [$r->id => "{$r->code} — {$r->designation?->name}"])->all())
                            ->searchable(),
                        Select::make('source_ids')
                            ->label('Sources')
                            ->multiple()
                            ->options(fn (): array => CandidateSource::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                    ]),
            ]);
    }
}
