<?php

namespace App\Filament\Resources\Offers\Schemas;

use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker;
use App\Models\CandidateApplication;
use App\Models\Offer;
use App\Services\OfferService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class OfferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('offer_code')
                    ->disabled()
                    ->dehydrated(false)
                    ->hidden(fn (string $operation): bool => $operation === 'create'),
                ApplicationPicker::make(modifyOptionsQueryUsing: fn (Builder $query): Builder => OfferService::eligibleApplications($query))
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation !== 'create')
                    ->helperText(fn (string $operation): string => $operation === 'create'
                        ? 'Only Selected applications without another open offer are listed.'
                        : 'An offer stays with its application.')
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $application = filled($state) ? ApplicationPicker::selectableApplications()->with('requisition')->find($state) : null;

                        foreach (self::requisitionDefaults($application) as $field => $value) {
                            $set($field, $value);
                        }
                    }),
                Section::make('Compensation')
                    ->columns(2)
                    ->disabled(fn (?Offer $record): bool => $record !== null && ! $record->termsAreEditable())
                    ->description(fn (?Offer $record): ?string => $record !== null && ! $record->termsAreEditable() ? 'Released terms are locked. Use "Request revision" to change them.' : null)
                    ->schema([
                        Select::make('designation_id')
                            ->relationship('designation', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('location_id')
                            ->relationship('location', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('offered_ctc')
                            ->numeric(),
                        TextInput::make('fixed_salary')
                            ->numeric(),
                        TextInput::make('variable_salary')
                            ->numeric(),
                        TextInput::make('joining_bonus')
                            ->numeric(),
                    ]),
                Section::make('Timeline')
                    ->columns(3)
                    ->disabled(fn (?Offer $record): bool => $record !== null && ! $record->termsAreEditable())
                    ->schema([
                        DatePicker::make('offer_date')
                            ->default(now())
                            ->required(),
                        DatePicker::make('offer_expiry'),
                        DatePicker::make('expected_joining_date'),
                    ]),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Designation/location the offer should default to, taken from the application's requisition.
     *
     * @return array{designation_id?: int, location_id?: int}
     */
    public static function requisitionDefaults(?CandidateApplication $application): array
    {
        return array_filter([
            'designation_id' => $application?->requisition?->designation_id,
            'location_id' => $application?->requisition?->location_id,
        ]);
    }
}
