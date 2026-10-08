<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Offers\Tables\OffersTable;
use App\Models\Offer;
use App\Services\OfferService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOffer extends EditRecord
{
    use GuardsDomainExceptions;

    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OffersTable::releaseAction(),
            OffersTable::requestRevisionAction(),
            OffersTable::customizeOfferLetterAction(),
            OffersTable::resetOfferLetterAction(),
            OffersTable::downloadOfferLetterAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * Phase 8.9 (P89-DQ-004): saved through OfferService::updateTerms(), which decides on the offer's
     * latest committed status under its row lock — a form opened on a Draft cannot change the terms of
     * an offer that was released meanwhile.
     *
     * @param  Offer  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return static::guarded('Not saved', fn (): Model => app(OfferService::class)->updateTerms($record, $data));
    }
}
