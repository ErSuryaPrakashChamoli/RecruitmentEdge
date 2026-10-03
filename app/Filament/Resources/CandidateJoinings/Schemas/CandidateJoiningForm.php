<?php

namespace App\Filament\Resources\CandidateJoinings\Schemas;

use App\Enums\DocumentStatus;
use App\Enums\OfferStatus;
use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CandidateJoiningForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Phase 8.10 (P810-DI-04): a joining follows an accepted offer — only applications
                // with one and no joining yet are offered; the offer itself is derived, never chosen
                // (CandidateJoiningService::createForApplication re-checks all of it).
                ApplicationPicker::make(modifyOptionsQueryUsing: fn (Builder $query): Builder => $query
                    ->whereHas('offers', fn (Builder $offers): Builder => $offers->where('status', OfferStatus::Accepted))
                    ->whereDoesntHave('joining'))
                    ->required()
                    ->helperText(fn (string $operation): ?string => $operation === 'create' ? 'Applications with an accepted offer and no joining record yet.' : null)
                    ->disabled(fn (string $operation): bool => $operation !== 'create'),
                Select::make('offer_id')
                    ->relationship('offer', 'offer_code')
                    ->disabled()
                    ->hiddenOn('create')
                    ->helperText('Set by the accepted offer.'),
                DatePicker::make('expected_doj')
                    ->helperText(fn (string $operation): ?string => $operation === 'create' ? 'Leave empty to use the offer\'s expected joining date.' : null)
                    ->required(fn (string $operation): bool => $operation !== 'create'),
                Select::make('documents_status')
                    ->label('Documents Status')
                    ->options(collect(DocumentStatus::cases())->mapWithKeys(fn (DocumentStatus $s) => [$s->value => $s->label()]))
                    ->default(DocumentStatus::Pending->value)
                    ->required(),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }
}
