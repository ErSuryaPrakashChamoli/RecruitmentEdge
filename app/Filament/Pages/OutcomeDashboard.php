<?php

namespace App\Filament\Pages;

use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\CandidateSource;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Location;
use App\Models\User;
use App\Services\Outcomes\OutcomeAnalyticsService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Outcome Loop™ (Phase 8.2): what happened after hiring decisions — joining, offers, time to hire,
 * time in stage, source to join, interview evidence of hires and going-forward status observations.
 * Read-only aggregates from recorded outcomes, scoped to the viewer's requisitions; every figure
 * shows its definition, population, period, sample size and what could not be observed.
 */
class OutcomeDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'EDGE Intelligence';

    protected static ?string $navigationLabel = 'Hiring Outcomes';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Hiring Outcomes';

    protected string $view = 'filament.pages.outcome-dashboard';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('outcomes.view');
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->subDays(364)->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 2, 'lg' => 4])->schema([
                    DatePicker::make('from')->label('From')->required()->live(),
                    DatePicker::make('to')->label('To')->required()->live(),
                    Select::make('requisition_id')->label('Requisition')->options(fn () => RecruitmentRequisitionResource::getEloquentQuery()->orderBy('code')->pluck('code', 'id'))->searchable()->live(),
                    Select::make('source_id')->label('Source')->options(fn () => CandidateSource::query()->orderBy('name')->pluck('name', 'id'))->searchable()->live(),
                    Select::make('department_id')->label('Department')->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id'))->searchable()->live(),
                    Select::make('designation_id')->label('Designation')->options(fn () => Designation::query()->orderBy('name')->pluck('name', 'id'))->searchable()->live(),
                    Select::make('location_id')->label('Location')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id'))->searchable()->live(),
                ]),
            ])
            ->statePath('data');
    }

    /**
     * @return array{period: array{from: string, to: string}, freshness: ?string, metrics: array<string, array<string, mixed>>, unavailable: array<string, string>}
     */
    public function report(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return app(OutcomeAnalyticsService::class)->report($user, collect($this->data)->only(OutcomeAnalyticsService::FILTERS)->all());
    }
}
